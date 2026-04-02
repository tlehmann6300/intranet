<?php

declare(strict_types=1);

namespace App\Services;

use Database;
use MailService;
use Exception;
use PDO;

/**
 * EasyVereinInventory Service
 * Manages inventory items and member assignments via the EasyVerein API v3.0.
 *
 * Uses the same authentication pattern as EasyVereinSync.php.
 */


class EasyVereinInventory {

    private const API_BASE                    = 'https://easyverein.com/api/v3.0';
    private const CACHE_TTL                   = 300; // seconds (5 minutes)
    private const FALLBACK_CONTACT_NAME       = 'Intra Ausleihe';
    private const CF_NAME_NOT_IN_EASYVEREIN   = 'Name nicht im easyverein';

    /** Request-level in-memory cache to avoid repeated file reads within one PHP request.
     *  This is a static property, so it persists only for the duration of the current
     *  PHP process / request and is automatically reset between separate HTTP requests.
     */
    private static ?array $requestCache = null;

    /**
     * Runtime token override – set by refreshToken() after a successful refresh.
     * Takes priority over the DB and .env-sourced constant within the current PHP process.
     */
    private static ?string $currentToken = null;

    /**
     * Return the path to the inventory cache file.
     * The filename includes a hash of the installation path to avoid
     * collisions between multiple application instances on the same server.
     */
    private function getCacheFile(): string {
        return sys_get_temp_dir() . '/easyverein_inventory_' . md5(__DIR__) . '_cache.json';
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Return the Bearer token for the EasyVerein API.
     *
     * Priority order (highest first):
     *   1. In-memory runtime override set by refreshToken()
     *   2. system_settings DB table (key: easyverein_api_token)
     *   3. EASYVEREIN_API_TOKEN constant sourced from .env
     *
     * The resolved token is cached in self::$currentToken so that subsequent
     * calls within the same PHP process do not repeat the DB lookup.
     *
     * @throws Exception If no API token can be found.
     */
    private function getApiToken(): string {
        // 1. In-memory override (set by refreshToken or a previous call)
        if (self::$currentToken !== null) {
            return self::$currentToken;
        }

        // 2. Check DB system_settings for a previously refreshed token
        try {
            $db   = Database::getContentDB();
            $stmt = $db->prepare(
                "SELECT setting_value FROM system_settings WHERE setting_key = 'easyverein_api_token' LIMIT 1"
            );
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && !empty($row['setting_value'])) {
                self::$currentToken = $row['setting_value'];
                return self::$currentToken;
            }
        } catch (Exception $e) {
            // DB unavailable – fall through to constant
        }

        // 3. Constant from .env
        $token = defined('EASYVEREIN_API_TOKEN') ? EASYVEREIN_API_TOKEN : '';
        if (empty($token)) {
            throw new Exception('EasyVerein API token not configured');
        }
        self::$currentToken = $token;
        return $token;
    }

    /**
     * Execute a cURL request and return the decoded JSON body.
     *
     * After each successful response the method inspects the response headers.
     * If the EasyVerein API signals that a token refresh is needed
     * (header "token_refresh_needed: true"), refreshToken() is called automatically
     * so that the new token is available for the next API call within this process.
     *
     * @param string     $method           HTTP method (GET, PATCH, PUT, DELETE, …)
     * @param string     $endpoint         Full URL to call
     * @param array|null $body             Request body (will be JSON-encoded); null for no body
     * @param bool       $skipTokenRefresh When true the auto-refresh check is skipped (used
     *                                     internally by refreshToken() to avoid recursion)
     * @return array Decoded JSON response (may be empty for 204 No Content)
     * @throws Exception On cURL error or non-2xx HTTP status
     */
    private function request(string $method, string $endpoint, ?array $body = null, bool $skipTokenRefresh = false): array {
        $token = $this->getApiToken();

        $doRequest = function (string $url) use ($method, $token, $body): array {
            $responseHeaders = [];
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
                'Accept: application/json',
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            // Collect response headers for token-refresh detection
            curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $header) use (&$responseHeaders) {
                $len   = strlen($header);
                $parts = explode(':', $header, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return $len;
            });

            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            }

            $response  = curl_exec($ch);
            $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            return [$response, $httpCode, $curlError, $responseHeaders];
        };

        // First attempt: primary endpoint (v3.0)
        [$response, $httpCode, $curlError, $responseHeaders] = $doRequest($endpoint);

        if ($response === false) {
            throw new Exception('cURL error: ' . $curlError);
        }

        // On 404, retry with v2.0 fallback endpoint
        if ($httpCode === 404 && strpos($endpoint, '/api/v3.0/') !== false) {
            $fallbackEndpoint = str_replace('/api/v3.0/', '/api/v2.0/', $endpoint);
            [$response, $httpCode, $curlError, $responseHeaders] = $doRequest($fallbackEndpoint);

            if ($response === false) {
                throw new Exception('cURL error: ' . $curlError);
            }
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $msg = "EasyVerein API [{$method} {$endpoint}] returned HTTP {$httpCode} - Details: " . $response;
            if ($httpCode === 403) {
                if (strpos($endpoint, 'contact-details') !== false) {
                    $msg .= ' 💡 HINWEIS: Dem API-Token fehlt das Recht, Mitglieder zu suchen. Bitte setze das Modul [Adressen] im easyVerein Token auf [Lesen].';
                } elseif (strpos($endpoint, 'lending') !== false) {
                    $msg .= ' 💡 HINWEIS: Dem API-Token fehlt das Recht, Ausleihen anzulegen. Bitte setze [Inventar] und [Ausleihen] auf [Lesen & Schreiben].';
                } elseif (strpos($endpoint, 'custom-fields') !== false) {
                    $msg .= ' 💡 HINWEIS: Dem API-Token fehlt das Recht, Individualfelder zu bearbeiten. Bitte setze [Individuelle Felder] auf [Lesen & Schreiben].';
                }
            }
            throw new Exception($msg);
        }

        // Automatic token refresh when the API signals it is needed
        if (!$skipTokenRefresh
            && isset($responseHeaders['token_refresh_needed'])
            && strtolower($responseHeaders['token_refresh_needed']) === 'true'
        ) {
            try {
                $this->refreshToken();
            } catch (Exception $e) {
                error_log('EasyVereinInventory: Token-Refresh nach API-Aufruf fehlgeschlagen: ' . $e->getMessage());
            }
        }

        // 204 No Content – return an empty array
        if ($httpCode === 204 || $response === '') {
            return [];
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Failed to parse JSON response: ' . json_last_error_msg());
        }

        return $data;
    }

    /**
     * Refresh the EasyVerein API token.
     *
     * Calls GET /api/v3.0/refresh-token and persists the new token:
     *   1. Updates self::$currentToken immediately so the next API call in this
     *      process uses the fresh token without any further DB or file I/O.
     *   2. Saves the token to the system_settings DB table
     *      (key: easyverein_api_token) with priority.
     *   3. Falls back to rewriting the EASYVEREIN_API_TOKEN line in the .env
     *      file if the DB write fails and the file is writable.
     *
     * @throws Exception If the refresh request fails or the API does not return
     *                   a token, indicating that manual intervention is required.
     */
    private function refreshToken(): void {
        $url = self::API_BASE . '/refresh-token';

        try {
            $data = $this->request('GET', $url, null, true);
        } catch (Exception $e) {
            $msg = 'EasyVerein Token-Refresh fehlgeschlagen: ' . $e->getMessage()
                . ' — Manueller Token-Eingriff in der .env Datei oder Datenbank (system_settings) notwendig.';
            error_log($msg);
            throw new Exception($msg);
        }

        $newToken = $data['token'] ?? null;
        if (empty($newToken)) {
            $msg = 'EasyVerein Token-Refresh: Kein Token in der API-Antwort erhalten'
                . ' — Manueller Token-Eingriff in der .env Datei oder Datenbank (system_settings) notwendig.';
            error_log($msg);
            throw new Exception($msg);
        }

        // Update in-memory token immediately for the rest of this request
        self::$currentToken = $newToken;

        // Persist to DB (priority)
        $savedToDb = false;
        try {
            $db = Database::getContentDB();
            $db->exec(
                "CREATE TABLE IF NOT EXISTS system_settings (
                    setting_key   VARCHAR(100) PRIMARY KEY,
                    setting_value TEXT,
                    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    updated_by    INT
                )"
            );
            $stmt = $db->prepare(
                "INSERT INTO system_settings (setting_key, setting_value)
                 VALUES ('easyverein_api_token', ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
            );
            $stmt->execute([$newToken]);
            $savedToDb = true;
            error_log('EasyVereinInventory: Token erfolgreich in Datenbank (system_settings) gespeichert.');
        } catch (Exception $e) {
            error_log('EasyVereinInventory: Token konnte nicht in Datenbank gespeichert werden: ' . $e->getMessage());
        }

        // Fall back to .env file if DB save failed
        if (!$savedToDb) {
            $this->updateEnvToken($newToken);
        }
    }

    /**
     * Update the EASYVEREIN_API_TOKEN value in the .env file.
     *
     * The method is a best-effort helper: it logs a descriptive error but does
     * not throw when the file cannot be read or written so that the caller can
     * handle missing persistence gracefully.
     *
     * @param string $newToken The new API token value to write.
     */
    private function updateEnvToken(string $newToken): void {
        $envFile = __DIR__ . '/../../.env';

        if (!file_exists($envFile) || !is_writable($envFile)) {
            error_log(
                'EasyVereinInventory: .env Datei nicht beschreibbar'
                . ' — Manueller Token-Eingriff notwendig. Bitte EASYVEREIN_API_TOKEN manuell aktualisieren.'
            );
            return;
        }

        $content = file_get_contents($envFile);
        if ($content === false) {
            error_log('EasyVereinInventory: .env Datei konnte nicht gelesen werden — Manueller Token-Eingriff notwendig.');
            return;
        }

        $count      = 0;
        $newContent = preg_replace(
            '/^EASYVEREIN_API_TOKEN=.*/m',
            'EASYVEREIN_API_TOKEN=' . $newToken,
            $content,
            -1,
            $count
        );

        if ($count === 0 || $newContent === null) {
            error_log(
                'EasyVereinInventory: EASYVEREIN_API_TOKEN nicht in .env gefunden'
                . ' — Manueller Token-Eingriff notwendig.'
            );
            return;
        }

        if (file_put_contents($envFile, $newContent) === false) {
            error_log(
                'EasyVereinInventory: .env Datei konnte nicht geschrieben werden'
                . ' — Manueller Token-Eingriff notwendig.'
            );
            return;
        }

        error_log('EasyVereinInventory: Token erfolgreich in .env Datei aktualisiert.');
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Fetch all inventory items from EasyVerein.
     *
     * Calls GET /api/v3.0/inventory-object and follows pagination links until
     * all items have been retrieved (handles the standard EasyVerein
     * results/next/data wrapper).
     *
     * @return array Array of inventory-item objects as returned by the API
     * @throws Exception On API or network errors
     */
    public function getItems(): array {
        // Return in-memory cache if populated within this PHP request
        if (self::$requestCache !== null) {
            return self::$requestCache;
        }

        $cacheFile = $this->getCacheFile();

        // Return cached data if the file exists and is younger than CACHE_TTL seconds
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < self::CACHE_TTL) {
            $raw = @file_get_contents($cacheFile);
            if ($raw !== false) {
                $cached = json_decode($raw, true);
                if (is_array($cached)) {
                    self::$requestCache = $cached;
                    return $cached;
                }
            }
        }

        $allItems = [];
        $url      = self::API_BASE . '/inventory-object?limit=100';

        while ($url !== null) {
            $data  = $this->request('GET', $url);
            $items = $data['results'] ?? $data['data'] ?? $data;

            if (!is_array($items)) {
                throw new Exception('Unexpected API response format for inventory-object');
            }

            $allItems = array_merge($allItems, $items);

            // Follow the `next` pagination link if present
            $url = $data['next'] ?? null;
        }

        // Persist the fresh result to the cache file (best-effort; ignore failures)
        @file_put_contents($cacheFile, json_encode($allItems));

        self::$requestCache = $allItems;
        return $allItems;
    }

    /**
     * Assign an inventory item to a member in EasyVerein.
     *
     * Reads the current item, verifies availability, then calls
     * PATCH /api/v1.7/inventory-items/{itemId} to store the member
     * assignment and decrement the available quantity by $quantity.
     *
     * Note: The EasyVerein REST API does not offer atomic compare-and-swap
     * operations, so a small time-of-check / time-of-use gap exists between
     * the availability read and the PATCH write. Callers should enforce
     * higher-level locking (e.g. a database transaction) when concurrent
     * assignments of the same item are possible.
     *
     * @param int    $itemId    EasyVerein inventory-item ID
     * @param int    $memberId  EasyVerein member ID to assign to
     * @param int    $quantity  Number of units to assign
     * @param string $purpose   Free-text reason / purpose of the assignment
     * @param string $userName  Display name of the borrower (used to update 'Aktuelle Ausleiher' custom field)
     * @param string $userEmail E-mail address of the borrower (used to update 'Entra E-Mail' custom field)
     * @return array API response data
     * @throws Exception On API or validation errors
     */
    public function assignItem(int $itemId, int $memberId, int $quantity, string $purpose, string $userName = '', string $userEmail = ''): array {
        if ($quantity < 1) {
            throw new Exception('Quantity must be at least 1');
        }

        // First, read the current item to obtain its stock and validate availability
        $url  = self::API_BASE . '/inventory-object/' . $itemId;
        $item = $this->request('GET', $url);

        $currentPieces = (int)($item['pieces'] ?? $item['inventory_quantity'] ?? $item['inventoryQuantity'] ?? $item['quantity'] ?? 0);
        if ($currentPieces < $quantity) {
            throw new Exception(
                "Insufficient stock: requested {$quantity}, available {$currentPieces}"
            );
        }

        // Build a timestamped log entry and prepend it to the existing note so that
        // the full checkout history is preserved in EasyVerein.
        $timestamp    = (new DateTime('now', new DateTimeZone('Europe/Berlin')))->format('d.m.Y H:i');
        $logEntry     = "⏳ [{$timestamp}] AUSGELIEHEN: {$quantity}x an {$memberId}";
        if ($purpose !== '') {
            $logEntry .= ". {$purpose}";
        }
        $existingNote = $item['note'] ?? $item['description'] ?? '';
        $updatedNote  = $logEntry . ($existingNote !== '' ? "\n" . $existingNote : '');

        // Build the PATCH payload:
        //   – assign the item to the member
        //   – reduce the stored quantity by the checked-out amount
        //   – store the updated log in the note field
        $payload = [
            'member'   => $memberId,
            'note'     => $updatedNote,
            'pieces'   => $currentPieces - $quantity,
        ];

        $result = $this->request('PATCH', $url, $payload);

        // Invalidate the inventory cache so the next page load fetches fresh data
        self::$requestCache = null;
        $cacheFile = $this->getCacheFile();
        if (file_exists($cacheFile)) {
            unlink($cacheFile);
        }

        // Update the EasyVerein custom fields on the inventory object:
        //   – 'Entra E-Mail'              → append borrower's e-mail address
        //   – 'Aktuelle Ausleiher'        → append borrower's display name
        //   – 'Zustand der letzten Rückgabe' → cleared (new checkout)
        if ($userName !== '' || $userEmail !== '') {
            $appendTo = [];
            if ($userName  !== '') $appendTo['Aktuelle Ausleiher'] = $userName;
            if ($userEmail !== '') $appendTo['Entra E-Mail']       = $userEmail;
            $this->modifyCustomFields($itemId,
                ['Zustand der letzten Rückgabe' => ''],
                $appendTo,
                []
            );
        }

        error_log(sprintf(
            'EasyVereinInventory: item %d assigned to member %d (qty %d, purpose: %s)',
            $itemId, $memberId, $quantity, $purpose
        ));

        return $result;
    }

    /**
     * Get all inventory objects currently lent to the given user.
     *
     * Makes a GET request to /lending?futureReturnDate=true&limit=100 with a
     * query that includes the parentInventoryObject and its customFields.
     * For each active lending the customFields of the parentInventoryObject are
     * inspected: if the field named 'Entra E-Mail' or 'Aktuelle Ausleiher'
     * contains $userIdentifier (case-insensitive substring match), the
     * parentInventoryObject is included in the returned array.
     *
     * @param int|string $userIdentifier User e-mail or identifier to match
     * @return array parentInventoryObject records assigned to the user
     * @throws Exception On API or network errors
     */
    public function getMyAssignedItems($userIdentifier): array {
        $url  = self::API_BASE . '/lending?future_return_date=true&limit=100&query={id,parent_inventory_object{*,custom_fields{id,value,custom_field{id,name}}}}';
        $data  = $this->request('GET', $url);
        $items = $data['results'] ?? $data['data'] ?? $data;

        if (!is_array($items)) {
            return [];
        }

        $lc      = strtolower((string)$userIdentifier);
        $myItems = [];

        foreach ($items as $lending) {
            $obj = $lending['parent_inventory_object'] ?? $lending['parentInventoryObject'] ?? null;
            if (!is_array($obj)) {
                continue;
            }

            $customFields = $obj['custom_fields'] ?? $obj['customFields'] ?? [];
            foreach ($customFields as $cf) {
                $cfMeta    = $cf['custom_field'] ?? $cf['customField'] ?? null;
                $fieldName = strtolower(is_array($cfMeta) ? ($cfMeta['name'] ?? '') : '');
                if ($fieldName !== 'entra e-mail' && $fieldName !== 'aktuelle ausleiher') {
                    continue;
                }
                $fieldValue = (string)($cf['value'] ?? '');
                foreach (array_map('trim', explode(',', $fieldValue)) as $entry) {
                    if (strtolower($entry) === $lc) {
                        $myItems[] = $obj;
                        break 2;
                    }
                }
            }
        }

        return $myItems;
    }

    /**
     * Fetch all inventory objects from EasyVerein (GET /api/v3.0/inventory-object).
     *
     * Follows pagination links until all items have been retrieved. Each item
     * contains at least the `name` and `pieces` fields as returned by the API.
     *
     * @return array Array of inventory-object records as returned by the API
     * @throws Exception On API or network errors
     */
    public function getInventoryObjects(): array {
        $allItems = [];
        $url      = self::API_BASE . '/inventory-object?limit=100';

        while ($url !== null) {
            $data  = $this->request('GET', $url);
            $items = $data['results'] ?? $data['data'] ?? $data;

            if (!is_array($items)) {
                throw new Exception('Unexpected API response format for inventory-object');
            }

            $allItems = array_merge($allItems, $items);
            $url      = $data['next'] ?? null;
        }

        return $allItems;
    }

    /**
     * Fetch all currently active lendings for a given inventory object.
     *
     * Calls GET /api/v3.0/lending?parent_inventory_object={id}&future_return_date=true
     * and follows pagination until all records have been retrieved.
     *
     * @param int|string $inventoryObjectId EasyVerein inventory-object ID
     * @return array Array of lending records as returned by the API
     * @throws Exception On API or network errors
     */
    public function getActiveLendings($inventoryObjectId): array {
        $allLendings = [];
        $url         = self::API_BASE . '/lending?parent_inventory_object='
            . urlencode((string)$inventoryObjectId) . '&future_return_date=true&limit=100';

        while ($url !== null) {
            $data  = $this->request('GET', $url);
            $items = $data['results'] ?? $data['data'] ?? $data;

            if (!is_array($items)) {
                error_log('EasyVereinInventory::getActiveLendings: unexpected API response format for lending endpoint');
                break;
            }

            $allLendings = array_merge($allLendings, $items);
            $url         = $data['next'] ?? null;
        }

        return $allLendings;
    }

    /**
     * Return the total number of pieces stored in EasyVerein for an inventory object.
     *
     * The field name varies across EasyVerein API versions:
     *   – 'pieces'             (v2 / v3)
     *   – 'inventory_quantity' (v3 snake_case)
     *   – 'inventoryQuantity'  (legacy v2 camelCase field)
     *   – 'quantity'           (fallback / custom deployments)
     *
     * @param int|string $inventoryObjectId EasyVerein inventory-object ID
     * @return int Total pieces
     * @throws Exception On API errors
     */
    public function getTotalPieces($inventoryObjectId): int {
        $url  = self::API_BASE . '/inventory-object/' . urlencode((string)$inventoryObjectId);
        $item = $this->request('GET', $url);
        return (int)($item['pieces'] ?? $item['inventory_quantity'] ?? $item['inventoryQuantity'] ?? $item['quantity'] ?? 0);
    }

    /**
     * Calculate the number of available units for a given inventory object and
     * date range, taking into account both EasyVerein active lendings and
     * locally stored inventory requests.
     *
     * The formula is:
     *   available = pieces
     *             – count of EasyVerein lendings that overlap [startDate, endDate]
     *             – SUM(quantity) of local inventory_requests with status
     *               'pending' or 'approved' that overlap [startDate, endDate]
     *
     * Overlap condition: lending.startDate <= endDate AND lending.endDate >= startDate
     *
     * @param int|string $inventoryObjectId EasyVerein inventory-object ID
     * @param string     $startDate         Start date of the requested period (YYYY-MM-DD)
     * @param string     $endDate           End date of the requested period (YYYY-MM-DD)
     * @return int Number of available units (minimum 0)
     * @throws Exception On API errors
     */
    public function getAvailableQuantity($inventoryObjectId, string $startDate, string $endDate): int {
        // 1. Fetch total pieces from the inventory object
        $url  = self::API_BASE . '/inventory-object/' . urlencode((string)$inventoryObjectId);
        $item = $this->request('GET', $url);
        $totalPieces = (int)($item['pieces'] ?? $item['inventory_quantity'] ?? $item['inventoryQuantity'] ?? $item['quantity'] ?? 0);

        // 2. Count EasyVerein active lendings that overlap the requested period
        $activeLendings = $this->getActiveLendings($inventoryObjectId);
        $evLent = 0;
        foreach ($activeLendings as $lending) {
            // Try multiple possible field names for lending start / end dates (v3.0 snake_case first, then v2.0 camelCase)
            $lendStart = $lending['start_date']   ?? $lending['startDate']    ?? $lending['lending_start'] ?? $lending['lendingStart'] ?? $lending['date_from'] ?? $lending['dateFrom'] ?? null;
            $lendEnd   = $lending['return_date']  ?? $lending['returnDate']   ?? $lending['lending_end']   ?? $lending['lendingEnd']   ?? $lending['end_date']  ?? $lending['dateTo']   ?? $lending['due_date'] ?? $lending['dueDate'] ?? null;

            $overlaps = false;
            if ($lendStart !== null && $lendEnd !== null) {
                // Normalise to YYYY-MM-DD for string comparison (ISO dates sort lexicographically)
                $lendStart = substr($lendStart, 0, 10);
                $lendEnd   = substr($lendEnd,   0, 10);
                if ($lendStart <= $endDate && $lendEnd >= $startDate) {
                    $overlaps = true;
                }
            } else {
                // No date information available – conservatively treat as overlapping
                $overlaps = true;
            }

            if ($overlaps) {
                // Use the quantity field from the lending record; fall back to 1 if absent
                $lendingQty = (int)($lending['quantity'] ?? $lending['pieces'] ?? $lending['amount'] ?? 1);
                $evLent    += max(1, $lendingQty);
            }
        }

        // 3. Sum quantities from local inventory_requests overlapping the period
        $localReserved = 0;
        try {
            $db   = Database::getContentDB();
            $stmt = $db->prepare(
                "SELECT COALESCE(SUM(quantity), 0) AS reserved
                 FROM inventory_requests
                 WHERE inventory_object_id = ?
                   AND status IN ('pending', 'approved')
                   AND start_date <= ?
                   AND end_date   >= ?"
            );
            $stmt->execute([(string)$inventoryObjectId, $endDate, $startDate]);
            $row           = $stmt->fetch(PDO::FETCH_ASSOC);
            $localReserved = (int)($row['reserved'] ?? 0);
        } catch (Exception $e) {
            error_log('EasyVereinInventory::getAvailableQuantity DB query failed: ' . $e->getMessage());
        }

        return max(0, $totalPieces - $evLent - $localReserved);
    }

    /**
     * Return an inventory item in EasyVerein.
     *
     * Reads the current item, then calls
     * PATCH /api/v1.7/inventory-items/{itemId} to clear the member
     * assignment and restore the quantity by $quantity.
     *
     * Note: The EasyVerein REST API does not offer atomic compare-and-swap
     * operations, so a small time-of-check / time-of-use gap exists between
     * the quantity read and the PATCH write. Callers should enforce
     * higher-level locking when concurrent operations on the same item are
     * possible.
     *
     * @param int $itemId   EasyVerein inventory-item ID
     * @param int $quantity Number of units being returned
     * @return array API response data
     * @throws Exception On API errors
     */
    public function returnItem(int $itemId, int $quantity): array {
        if ($quantity < 1) {
            throw new Exception('Quantity must be at least 1');
        }

        // Read the current item to obtain its stock
        $url  = self::API_BASE . '/inventory-object/' . $itemId;
        $item = $this->request('GET', $url);

        $currentPieces = (int)($item['pieces'] ?? $item['inventory_quantity'] ?? $item['inventoryQuantity'] ?? $item['quantity'] ?? 0);

        // Determine who is returning the item for the log entry.
        $memberRaw = $item['member'] ?? null;
        if (is_array($memberRaw)) {
            $memberRef = $memberRaw['username'] ?? $memberRaw['name'] ?? $memberRaw['id'] ?? 'Unbekannt';
        } else {
            $memberRef = $memberRaw ?? 'Unbekannt';
        }

        // Build a timestamped return log entry and prepend it to the existing note.
        $timestamp    = (new DateTime('now', new DateTimeZone('Europe/Berlin')))->format('d.m.Y H:i');
        $logEntry     = "✅ [{$timestamp}] ZURÜCKGEGEBEN: {$quantity}x von {$memberRef}.";
        $existingNote = $item['note'] ?? $item['description'] ?? '';
        $updatedNote  = $logEntry . ($existingNote !== '' ? "\n" . $existingNote : '');

        // Build the PATCH payload:
        //   – clear the member assignment
        //   – restore the stored quantity
        //   – keep the updated log in the note field
        $payload = [
            'member' => null,
            'note'   => $updatedNote,
            'pieces' => $currentPieces + $quantity,
        ];

        $result = $this->request('PATCH', $url, $payload);

        // Invalidate the inventory cache so the next page load fetches fresh data
        self::$requestCache = null;
        $cacheFile = $this->getCacheFile();
        if (file_exists($cacheFile)) {
            unlink($cacheFile);
        }

        error_log(sprintf(
            'EasyVereinInventory: item %d returned (qty %d restored)',
            $itemId, $quantity
        ));

        return $result;
    }

    /**
     * Approve a rental request.
     *
     * 1. Loads the pending request from the local DB.
     * 2. Resolves the borrower's EasyVerein contact ID via getContactIdByName().
     *    If the borrower is not found in EasyVerein, falls back to the placeholder
     *    contact "Intra Ausleihe" and records the original name in the custom field
     *    "Name nicht im easyverein".
     * 3. Creates the official lending record in EasyVerein via POST /api/v3.0/lending.
     * 4. Queries all currently active (approved) rentals for this item from the local DB
     *    (including the request being approved now) and builds multiline strings for the
     *    custom fields 'Aktuelle Ausleiher' and 'Entra E-Mail'.
     * 5. Fetches the individual fields via GET /api/v3.0/inventory-object-custom-field-assignment?inventory_object={id},
     *    updates 'Aktuelle Ausleiher' and 'Entra E-Mail' with the multiline strings, and
     *    clears 'Zustand der letzten Rückgabe'.
     *    When the fallback was used, also sets "Name nicht im easyverein" to $userName.
     *    For each field, PATCHes /api/v3.0/custom-field-values/{id} if a value already
     *    exists, or POSTs /api/v3.0/custom-field-values to create it otherwise.
     * 6. Updates the local DB status to 'approved'.
     *
     * @param int    $requestId  Local inventory_requests row ID
     * @param string $userName   Display name of the borrower
     * @param string $userEmail  E-mail address of the borrower
     * @param int    $quantity   Number of units approved for lending
     * @return void
     * @throws Exception On database or API errors
     */
    public function approveRental(int $requestId, string $userName, string $userEmail, int $quantity): void {
        // 1. Load the pending request from the local DB
        $db   = Database::getContentDB();
        $stmt = $db->prepare(
            "SELECT * FROM inventory_requests WHERE id = ? AND status = 'pending'"
        );
        $stmt->execute([$requestId]);
        $req = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$req) {
            throw new Exception("Inventory request #{$requestId} not found or not pending");
        }

        // 2. Resolve the borrower's EasyVerein contact ID.
        //    If the borrower is not found, fall back to the "Intra Ausleihe" placeholder.
        $fallbackUsed = false;
        try {
            $evContactId = $this->getContactIdByName($userName);
        } catch (Exception $e) {
            error_log(sprintf(
                'EasyVereinInventory::approveRental: user "%s" not found in EasyVerein, falling back to "%s". Original error: %s',
                $userName, self::FALLBACK_CONTACT_NAME, $e->getMessage()
            ));
            try {
                $evContactId = $this->getContactIdByName(self::FALLBACK_CONTACT_NAME);
            } catch (Exception $fallbackEx) {
                throw new Exception(sprintf(
                    'Fallback contact "%s" not found in EasyVerein: %s',
                    self::FALLBACK_CONTACT_NAME, $fallbackEx->getMessage()
                ));
            }
            $fallbackUsed = true;
        }

        // 3. Create the official lending record in EasyVerein
        $this->createLending(
            $req['inventory_object_id'],
            $evContactId,
            $quantity,
            $req['start_date'],
            $req['end_date']
        );

        // 4. Append the new borrower to the custom fields on the inventory object.
        //    When the fallback was used, also record the original user name.
        $setTo = ['Zustand der letzten Rückgabe' => ''];
        if ($fallbackUsed) {
            $setTo[self::CF_NAME_NOT_IN_EASYVEREIN] = $userName;
        } else {
            $setTo[self::CF_NAME_NOT_IN_EASYVEREIN] = '';
        }
        $appendTo = [];
        if ($userName  !== '') $appendTo['Aktuelle Ausleiher'] = $userName;
        if ($userEmail !== '') $appendTo['Entra E-Mail']       = $userEmail;
        $this->modifyCustomFields((int)$req['inventory_object_id'],
            $setTo,
            $appendTo,
            []
        );

        // 5. Update local DB status to 'approved' (only after all API calls succeed)
        $upd = $db->prepare(
            "UPDATE inventory_requests SET status = 'approved' WHERE id = ?"
        );
        $upd->execute([$requestId]);

        error_log(sprintf(
            'EasyVereinInventory: request %d approved for %s (%s), inventory object %s',
            $requestId, $userName, $userEmail, $req['inventory_object_id']
        ));
    }

    /**
     * Verify the return of a rental request.
     *
     * 1. Finds the active EasyVerein lending for the inventory object and sets
     *    its return_date to today via PATCH /api/v3.0/lending/{id}.
     * 2. Queries all remaining active (approved) rentals for this item from the local DB
     *    (excluding the request being returned) and builds multiline strings for the
     *    custom fields 'Aktuelle Ausleiher' and 'Entra E-Mail'. Sets both to '' if nobody
     *    still has the item.
     * 3. Finds the individual field 'Zustand der letzten Rückgabe' and writes
     *    '$condition - Geprüft am [DATE] durch $adminName. Notiz: $notes'.
     *    For each field, PATCHes /api/v3.0/custom-field-values/{id} if a value already
     *    exists, or POSTs /api/v3.0/custom-field-values to create it otherwise.
     * 4. Updates the local DB status to 'returned'.
     *
     * @param int    $requestId  Local inventory_requests row ID
     * @param string $adminName  Display name of the board member performing the verification
     * @param string $condition  Condition label of the returned item
     * @param string $notes      Optional notes about the return
     * @param string $userName   Display name of the borrower (skips internal DB lookup when provided)
     * @param string $userEmail  E-mail address of the borrower (skips internal DB lookup when provided)
     * @return void
     * @throws Exception On database or API errors
     */
    public function verifyReturn(int $requestId, string $adminName, string $condition, string $notes, string $userName = '', string $userEmail = ''): void {
        // 1. Load the approved (or pending_return) request from the local DB
        $db   = Database::getContentDB();
        $stmt = $db->prepare(
            "SELECT * FROM inventory_requests WHERE id = ? AND status IN ('approved', 'pending_return')"
        );
        $stmt->execute([$requestId]);
        $req = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$req) {
            throw new Exception("Inventory request #{$requestId} not found or not approved");
        }

        $tz             = new DateTimeZone('Europe/Berlin');
        $today          = (new DateTime('now', $tz))->format('Y-m-d');
        $todayFormatted = (new DateTime('now', $tz))->format('d.m.Y');

        // 2. End the active lending in EasyVerein by patching its return_date to today.
        //    Wrapped in try-catch so an EasyVerein API failure does not prevent
        //    the local DB update that marks the item as available again.
        try {
            $activeLendings  = $this->getActiveLendings($req['inventory_object_id']);
            $lendingPatched  = false;
            foreach ($activeLendings as $lending) {
                $lendingId = $lending['id'] ?? null;
                if ($lendingId === null) {
                    continue;
                }
                $this->request('PATCH', self::API_BASE . '/lending/' . $lendingId, ['return_date' => $today]);
                $lendingPatched = true;
                break;
            }
            if (!$lendingPatched) {
                error_log(sprintf(
                    'EasyVereinInventory::verifyReturn: no active lending found for inventory object %s (request %d)',
                    $req['inventory_object_id'], $requestId
                ));
            }
        } catch (Exception $evEx) {
            error_log(sprintf(
                'EasyVereinInventory::verifyReturn: lending patch failed for inventory object %s (request %d): %s',
                $req['inventory_object_id'], $requestId, $evEx->getMessage()
            ));
        }

        // 3. Look up the returning user's display name and e-mail to remove from custom fields.
        //    Use caller-supplied values when provided; otherwise resolve from the local DB.
        $returnUserName  = $userName;
        $returnUserEmail = $userEmail;
        if ($returnUserName === '' || $returnUserEmail === '') {
            try {
                $userDb = Database::getUserDB();
                $uStmt  = $userDb->prepare(
                    "SELECT first_name, last_name, email FROM users WHERE id = ? LIMIT 1"
                );
                $uStmt->execute([(int)$req['user_id']]);
                $uRow = $uStmt->fetch(PDO::FETCH_ASSOC);
                if ($uRow) {
                    if ($returnUserEmail === '') {
                        $returnUserEmail = $uRow['email'] ?? '';
                    }
                    if ($returnUserName === '') {
                        $returnUserName = trim(($uRow['first_name'] ?? '') . ' ' . ($uRow['last_name'] ?? ''));
                        if ($returnUserName === '') {
                            $returnUserName = $returnUserEmail;
                        }
                    }
                }
            } catch (Exception $e) {
                error_log('EasyVereinInventory::verifyReturn: failed to look up user ' . $req['user_id'] . ': ' . $e->getMessage());
            }
        }

        // 4. Update custom fields: remove this borrower and write the condition text.
        $conditionText = ($condition !== '' ? "{$condition} - " : '') . "Geprüft am {$todayFormatted} durch {$adminName}."
            . ($notes !== '' ? " Notiz: {$notes}" : '');
        $removeFrom = [];
        if ($returnUserName  !== '') $removeFrom['Aktuelle Ausleiher'] = $returnUserName;
        if ($returnUserEmail !== '') $removeFrom['Entra E-Mail']       = $returnUserEmail;
        $this->modifyCustomFields((int)$req['inventory_object_id'],
            ['Zustand der letzten Rückgabe' => $conditionText, self::CF_NAME_NOT_IN_EASYVEREIN => ''],
            [],
            $removeFrom
        );

        // 5. Update local DB status to 'returned' so the item is available again locally.
        $upd = $db->prepare(
            "UPDATE inventory_requests
                SET status = 'returned', returned_at = NOW(), return_notes = ?
              WHERE id = ?"
        );
        $upd->execute([$notes !== '' ? $notes : null, $requestId]);

        error_log(sprintf(
            'EasyVereinInventory: request %d verified as returned by %s (condition: %s), inventory object %s',
            $requestId, $adminName, $condition, $req['inventory_object_id']
        ));
    }

    /**
     * Verify the return of a direct rental (inventory_rentals workflow).
     *
     * Mirrors the EasyVerein-side steps of verifyReturn() but operates on an
     * EasyVerein inventory-object ID directly instead of an inventory_requests
     * row.  Called by Inventory::approveReturn() so that board verifications
     * always update both the local DB and EasyVerein.
     *
     * 1. Finds the active EasyVerein lending for the inventory object and sets
     *    its return_date to today via PATCH /api/v3.0/lending/{id}.
     * 2. Removes the borrower from the custom fields on the inventory object.
     *
     * Errors from EasyVerein are logged but do not throw, so that a temporary
     * API outage never prevents the local DB record from being deleted.
     *
     * @param int    $easyvereinItemId EasyVerein inventory-object ID
     * @param string $adminName        Display name of the board member verifying the return
     * @param string $userName         Display name of the borrower (used to remove from custom fields)
     * @param string $userEmail        E-mail address of the borrower (used to remove from custom fields)
     * @param string $notes            Optional remarks about the return
     */
    public function verifyReturnForRental(int $easyvereinItemId, string $adminName, string $userName = '', string $userEmail = '', string $notes = ''): void {
        $tz             = new DateTimeZone('Europe/Berlin');
        $today          = (new DateTime('now', $tz))->format('Y-m-d');
        $todayFormatted = (new DateTime('now', $tz))->format('d.m.Y');

        // 1. End the active lending in EasyVerein by patching its return_date to today.
        //    Wrapped in try-catch so an EasyVerein API failure does not prevent
        //    the local DB record from being deleted.
        try {
            $activeLendings = $this->getActiveLendings($easyvereinItemId);
            $lendingPatched = false;
            foreach ($activeLendings as $lending) {
                $lendingId = $lending['id'] ?? null;
                if ($lendingId === null) {
                    continue;
                }
                $this->request('PATCH', self::API_BASE . '/lending/' . $lendingId, ['return_date' => $today]);
                $lendingPatched = true;
                break;
            }
            if (!$lendingPatched) {
                error_log(sprintf(
                    'EasyVereinInventory::verifyReturnForRental: no active lending found for inventory object %d',
                    $easyvereinItemId
                ));
            }
        } catch (Exception $evEx) {
            error_log(sprintf(
                'EasyVereinInventory::verifyReturnForRental: lending patch failed for inventory object %d: %s',
                $easyvereinItemId, $evEx->getMessage()
            ));
        }

        // 2. Remove the borrower from custom fields.
        $byClause   = $adminName !== '' ? " durch {$adminName}" : '';
        $returnText = "Zurückgegeben am {$todayFormatted}{$byClause}."
            . ($notes !== '' ? " Notiz: {$notes}" : '');
        $removeFrom = [];
        if ($userName  !== '') $removeFrom['Aktuelle Ausleiher'] = $userName;
        if ($userEmail !== '') $removeFrom['Entra E-Mail']       = $userEmail;
        try {
            $this->modifyCustomFields($easyvereinItemId,
                ['Zustand der letzten Rückgabe' => $returnText, self::CF_NAME_NOT_IN_EASYVEREIN => ''],
                [],
                $removeFrom
            );
        } catch (Exception $cfEx) {
            error_log(sprintf(
                'EasyVereinInventory::verifyReturnForRental: custom field update failed for inventory object %d: %s',
                $easyvereinItemId, $cfEx->getMessage()
            ));
        }

        error_log(sprintf(
            'EasyVereinInventory: direct rental for inventory object %d verified as returned by %s',
            $easyvereinItemId, $adminName
        ));
    }

    /**
     * Modify inventory custom fields via intelligent append, remove, or set operations.
     *
     * Fetches existing custom field values for the given inventory object and
     * applies one of three operations per field:
     *   – $setTo:      Overwrite the value directly.
     *   – $appendTo:   Split the current value at commas, add the new value if not
     *                  already present, and rejoin with commas.
     *   – $removeFrom: Split the current value at commas, remove the given value,
     *                  and rejoin with commas.
     * After computing the new value, PATCHes an existing custom-field-value record
     * or POSTs a new one.  Errors per field are logged but do not abort the caller.
     *
     * @param int   $objectId   EasyVerein inventory-object ID
     * @param array $setTo      fieldName → value to set directly
     * @param array $appendTo   fieldName → value to append (if not already present)
     * @param array $removeFrom fieldName → value to remove
     */
    private function modifyCustomFields(int $objectId, array $setTo, array $appendTo, array $removeFrom): void {
        $cfUrl = self::API_BASE . '/inventory-object-custom-field-assignment?inventory_object=' . $objectId
            . '&query={id,value,custom_field{id,name}}';

        try {
            $cfData = $this->request('GET', $cfUrl);
        } catch (Exception $e) {
            error_log(sprintf(
                'EasyVereinInventory::modifyCustomFields: failed to fetch custom fields for object %d: %s',
                $objectId, $e->getMessage()
            ));
            return;
        }

        $existingFields = $cfData['results'] ?? $cfData['data'] ?? $cfData;
        if (!is_array($existingFields)) {
            return;
        }

        $allFieldNames = array_unique(array_merge(array_keys($setTo), array_keys($appendTo), array_keys($removeFrom)));

        foreach ($existingFields as $field) {
            $fieldName        = $field['custom_field']['name'] ?? $field['customField']['name'] ?? '';
            $customFieldDefId = $field['custom_field']['id']   ?? $field['customField']['id']   ?? null;
            $valueId          = $field['id']                  ?? null;

            if (!in_array($fieldName, $allFieldNames, true) || $customFieldDefId === null) {
                continue;
            }

            $currentValue = (string)($field['value'] ?? '');
            $newValue     = $currentValue;

            if (array_key_exists($fieldName, $setTo)) {
                $newValue = $setTo[$fieldName];
            } elseif (array_key_exists($fieldName, $appendTo)) {
                $toAdd = $appendTo[$fieldName];
                $parts = $currentValue !== '' ? array_values(array_filter(array_map('trim', explode(',', $currentValue)))) : [];
                if ($toAdd !== '' && !in_array($toAdd, $parts, true)) {
                    $parts[] = $toAdd;
                }
                $newValue = implode(', ', $parts);
            } elseif (array_key_exists($fieldName, $removeFrom)) {
                $toRemove = $removeFrom[$fieldName];
                $parts    = $currentValue !== '' ? array_values(array_filter(array_map('trim', explode(',', $currentValue)))) : [];
                $parts    = array_values(array_diff($parts, [$toRemove]));
                $newValue = implode(', ', $parts);
            }

            try {
                if ($valueId !== null) {
                    $this->request(
                        'PATCH',
                        self::API_BASE . '/custom-field-values/' . $valueId,
                        ['value' => $newValue]
                    );
                } else {
                    $this->request(
                        'POST',
                        self::API_BASE . '/custom-field-values',
                        [
                            'custom_field'            => $customFieldDefId,
                            'related_inventory_object' => $objectId,
                            'value'                  => $newValue,
                        ]
                    );
                }
            } catch (Exception $e) {
                error_log(sprintf(
                    'EasyVereinInventory::modifyCustomFields: failed to update field "%s" for object %d: %s',
                    $fieldName, $objectId, $e->getMessage()
                ));
            }
        }
    }

    /**
     * Resolve a display name to an EasyVerein contact ID.
     *
     * Calls GET /api/v3.0/contact-details?search={name} and returns the id of
     * the first matching contact as an integer.
     *
     * @param string $name Display name to look up
     * @return int EasyVerein contact ID
     * @throws Exception If no contact is found for the given name
     */
    private function getContactIdByName(string $name): int {
        $url  = self::API_BASE . '/contact-details?search=' . urlencode($name);
        $data = $this->request('GET', $url);

        $results = $data['results'] ?? $data['data'] ?? [];

        if (!empty($results) && isset($results[0]['id'])) {
            return (int)$results[0]['id'];
        }

        throw new Exception(
            'Nutzer nicht im easyVerein gefunden. Der Name (' . $name . ') muss in easyVerein existieren.'
        );
    }

    /**
     * Create a lending record in EasyVerein.
     *
     * Calls POST /api/v3.0/lending with the required fields for a new loan.
     *
     * @param int|string $parentInventoryObject EasyVerein inventory-object ID
     * @param int        $borrowAddress         EasyVerein contact ID of the borrower
     * @param int        $quantity              Number of units to lend
     * @param string     $borrowingDate         Start date of the loan (YYYY-MM-DD)
     * @param string     $returnDate            Expected return date (YYYY-MM-DD)
     * @return array API response data
     * @throws Exception On API or validation errors
     */
    public function createLending($parentInventoryObject, int $borrowAddress, int $quantity, string $borrowingDate, string $returnDate): array {
        if ($quantity < 1) {
            throw new Exception('Quantity must be at least 1');
        }

        $url     = self::API_BASE . '/lending';
        $payload = [
            'parent_inventory_object' => (string)$parentInventoryObject,
            'borrow_address'          => $borrowAddress,
            'quantity'                => $quantity,
            'borrowing_date'          => substr($borrowingDate, 0, 10),
            'return_date'             => substr($returnDate, 0, 10),
        ];

        $result = $this->request('POST', $url, $payload);

        error_log(sprintf(
            'EasyVereinInventory: lending created for item %s, borrower %s (qty %d, %s – %s)',
            $parentInventoryObject, $borrowAddress, $quantity, $borrowingDate, $returnDate
        ));

        return $result;
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Services\EasyVereinInventory;
use App\Services\MicrosoftGraphService;
use App\Services\EasyVereinSync;

/**
 * Inventory Model
 * Delegates all inventory operations to the EasyVereinInventory service.
 * All PDO database queries have been removed; the EasyVerein API is the single
 * source of truth for inventory data.
 */

class Inventory extends Model
{
    protected $connection = 'inventory';
    protected $table = 'inventory_items';
    protected static $unguarded = true;
    protected $timestamps = false;

    /**
     * Master Data fields that are synced with EasyVerein.
     * Kept for backwards-compatibility with callers.
     */
    const MASTER_DATA_FIELDS = ['name', 'description', 'quantity', 'unit_price'];

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /** Return a fresh EasyVereinInventory instance. */
    private static function evi(): EasyVereinInventory {
        return new EasyVereinInventory();
    }

    /**
     * Fetch a map of inventory_object_id => approved loaned quantity for items
     * that have currently active approved rentals (today is within rental period).
     *
     * @return array<string, int>  Keys are EasyVerein inventory-object IDs (strings).
     */
    private static function getApprovedLoanedQuantities(): array {
        try {
            $db   = \Database::getContentDB();
            $stmt = $db->query(
                "SELECT inventory_object_id, COALESCE(SUM(quantity), 0) AS loaned
                 FROM inventory_requests
                 WHERE status = 'approved'
                   AND start_date <= CURDATE()
                   AND end_date   >= CURDATE()
                 GROUP BY inventory_object_id"
            );
            $result = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $result[(string)$row['inventory_object_id']] = (int)$row['loaned'];
            }
            return $result;
        } catch (Exception $e) {
            error_log('Inventory::getApprovedLoanedQuantities failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Normalise a raw EasyVerein API item into the field layout that the views
     * expect (mirrors the columns previously returned by the local DB queries).
     *
     * @param array $ev            Raw EasyVerein item data.
     * @param int   $approvedLoaned Sum of approved active rental quantities for this item.
     */
    private static function mapItem(array $ev, int $approvedLoaned = 0): array {
        $pieces      = (int)($ev['pieces'] ?? $ev['inventoryQuantity'] ?? $ev['quantity'] ?? 0);
        $loanedCount = (isset($ev['member']) && $ev['member'] !== null && $ev['member'] !== '') ? 1 : 0;

        return [
            'id'                        => $ev['id']            ?? null,
            'easyverein_id'             => $ev['id']            ?? null,
            'name'                      => $ev['name']          ?? '',
            'description'               => $ev['note']          ?? $ev['description'] ?? '',
            'serial_number'             => $ev['serial_number'] ?? $ev['inventoryNumber'] ?? $ev['serialNumber'] ?? null,
            'category_id'               => null,
            'location_id'               => null,
            'quantity'                  => $pieces,
            'min_stock'                 => 0,
            'unit'                      => $ev['unit']          ?? 'Stück',
            'unit_price'                => (float)($ev['acquisitionPrice'] ?? $ev['price'] ?? $ev['unit_price'] ?? 0),
            'image_path'                => $ev['picture']       ?? $ev['image'] ?? $ev['image_path'] ?? null,
            'notes'                     => $ev['note']          ?? null,
            'created_at'                => $ev['created_at']    ?? null,
            'updated_at'                => $ev['updated_at']    ?? null,
            'last_synced_at'            => null,
            'is_archived_in_easyverein' => 0,
            'loaned_count'              => $loanedCount,
            'category_name'             => $ev['category']      ?? null,
            'category_color'            => '#3B82F6',
            'location_name'             => $ev['locationName']  ?? $ev['location'] ?? null,
            'available_quantity'        => max(0, $pieces - $approvedLoaned),
        ];
    }

    // -------------------------------------------------------------------------
    // Read
    // -------------------------------------------------------------------------

    /**
     * Get item by ID (EasyVerein item ID).
     */
    public static function getById($id): ?array {
        try {
            $loanedMap = self::getApprovedLoanedQuantities();
            foreach (self::evi()->getItems() as $ev) {
                if ((int)($ev['id'] ?? 0) === (int)$id) {
                    return self::mapItem($ev, $loanedMap[(string)($ev['id'] ?? '')] ?? 0);
                }
            }
        } catch (Exception $e) {
            // Any API failure (404, network, auth) means the item is unavailable
            error_log('EasyVerein inventory fetch failed: ' . $e->getMessage());
        }
        return null;
    }

    /**
     * Get available stock for an item.
     */
    public static function getAvailableStock($id): int {
        $item = self::getById($id);
        return $item ? (int)$item['available_quantity'] : 0;
    }

    /**
     * Calculate the total number of currently rented (active) units for a given
     * inventory item.
     *
     * Sums the quantity of all approved rentals in inventory_requests where today
     * falls within the rental period (start_date <= today <= end_date).
     * Returns 0 when no active rentals exist or on any database error.
     *
     * @param int|string $inventoryId EasyVerein inventory-object ID
     * @return int Total rented units (0 if none or on error)
     */
    public static function getRentedQuantity($inventoryId): int {
        try {
            $db   = \Database::getContentDB();
            $stmt = $db->prepare(
                "SELECT COALESCE(SUM(quantity), 0) AS rented
                 FROM inventory_requests
                 WHERE inventory_object_id = ?
                   AND status = 'approved'
                   AND start_date <= CURDATE()
                   AND end_date   >= CURDATE()"
            );
            $stmt->execute([(string)$inventoryId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return (int)($row['rented'] ?? 0);
        } catch (Exception $e) {
            error_log('Inventory::getRentedQuantity failed: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Calculate the available quantity for a given inventory item.
     *
     * Formula: total pieces (from EasyVerein API) MINUS the currently rented
     * quantity (via getRentedQuantity()).
     *
     * @param int|string $inventoryId EasyVerein inventory-object ID
     * @return int Number of available units (minimum 0)
     */
    public static function getAvailableQuantity($inventoryId): int {
        $inventoryId = (string)$inventoryId;
        try {
            $totalPieces = self::evi()->getTotalPieces($inventoryId);
            $rented      = self::getRentedQuantity($inventoryId);
            return max(0, $totalPieces - $rented);
        } catch (Exception $e) {
            error_log('Inventory::getAvailableQuantity failed: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Check whether an inventory item is available for borrowing.
     *
     * Returns true when getAvailableQuantity() > 0, i.e. at least one unit
     * is not currently rented out.
     *
     * @param int|string $inventoryId EasyVerein inventory-object ID
     * @return bool True if at least one unit is available
     */
    public static function isAvailable($inventoryId): bool {
        return self::getAvailableQuantity($inventoryId) > 0;
    }

    /**
     * Get all items with optional filters.
     */
    public static function getAll($filters = []): array {
        try {
            $rawItems = self::evi()->getItems();
            if (empty($rawItems)) {
                error_log('EasyVerein inventory: getItems() returned empty array – check API token and endpoint');
            }
            $loanedMap = self::getApprovedLoanedQuantities();
            $items = array_map(
                fn($ev) => self::mapItem($ev, $loanedMap[(string)($ev['id'] ?? '')] ?? 0),
                $rawItems
            );
        } catch (Exception $e) {
            // Any API failure (404, network, auth) should not crash the page
            error_log('EasyVerein inventory fetch failed: ' . $e->getMessage());
            return [];
        }

        // Filter by category name
        if (!empty($filters['category_id'])) {
            $items = array_filter($items, fn($i) => $i['category_name'] === $filters['category_id']);
        }

        // Filter by location name
        if (!empty($filters['location'])) {
            $items = array_filter($items, fn($i) => $i['location_name'] === $filters['location']);
        }

        // Full-text search on name / description
        if (!empty($filters['search'])) {
            $needle = strtolower($filters['search']);
            $items  = array_filter($items, function ($i) use ($needle) {
                return strpos(strtolower($i['name']), $needle) !== false
                    || strpos(strtolower($i['description']), $needle) !== false;
            });
        }

        // Low-stock filter – flags items with no available units (EasyVerein has no min_stock concept)
        if (!empty($filters['low_stock'])) {
            $items = array_filter($items, fn($i) => $i['available_quantity'] <= 0);
        }

        // Sorting (whitelist)
        $sort = $filters['sort'] ?? 'name_asc';
        usort($items, function ($a, $b) use ($sort) {
            switch ($sort) {
                case 'name_desc':     return strcmp($b['name'], $a['name']);
                case 'quantity_asc':  return $a['quantity'] <=> $b['quantity'];
                case 'quantity_desc': return $b['quantity'] <=> $a['quantity'];
                case 'price_asc':     return $a['unit_price'] <=> $b['unit_price'];
                case 'price_desc':    return $b['unit_price'] <=> $a['unit_price'];
                default:              return strcmp($a['name'], $b['name']);
            }
        });

        return array_values($items);
    }

    // -------------------------------------------------------------------------
    // Checkout / Return  (delegated to EasyVereinInventory)
    // -------------------------------------------------------------------------

    /**
     * Checkout / borrow an item.
     *
     * Assigns the item to the member in EasyVerein.  Purpose and expected
     * return date are stored together in the EasyVerein note field so that
     * the loan is fully documented there.
     *
     * @param int         $itemId            EasyVerein item ID
     * @param int         $userId            EasyVerein member ID of the borrower
     * @param int         $quantity          Number of units to borrow
     * @param string      $purpose           Reason / purpose of the loan
     * @param string|null $destination       Optional destination / usage location
     * @param string|null $expectedReturnDate Optional expected return date (any format)
     * @param string|null $start_date        Optional start date of the rental (stored in rented_at)
     * @return array ['success' => bool, 'message' => string]
     */
    public static function checkoutItem($itemId, $userId, $quantity, $purpose, $destination = null, $expectedReturnDate = null, $start_date = null): array {
        if ((int)$quantity <= 0) {
            return ['success' => false, 'message' => 'Ungültige Menge'];
        }

        // Build a combined note that documents purpose and return date in EasyVerein.
        $noteParts = [];
        if ($purpose !== null && $purpose !== '') {
            $noteParts[] = 'Zweck: ' . $purpose;
        }
        if ($destination !== null && $destination !== '') {
            $noteParts[] = 'Ort: ' . $destination;
        }
        if ($expectedReturnDate !== null && $expectedReturnDate !== '') {
            $noteParts[] = 'Rückgabe bis: ' . $expectedReturnDate;
        }
        $note = implode(' | ', $noteParts);

        try {
            // Look up the borrower's name and e-mail so that the EasyVerein custom fields
            // 'Entra E-Mail' and 'Aktuelle Ausleiher' can be updated during checkout.
            $userName  = '';
            $userEmail = '';
            try {
                $userDb   = \Database::getUserDB();
                $userStmt = $userDb->prepare("SELECT first_name, last_name, email FROM users WHERE id = ?");
                $userStmt->execute([(int)$userId]);
                $userRow = $userStmt->fetch(PDO::FETCH_ASSOC);
                if ($userRow) {
                    $userName  = trim(($userRow['first_name'] ?? '') . ' ' . ($userRow['last_name'] ?? ''));
                    $userEmail = $userRow['email'] ?? '';
                    // If no name is stored locally, try Microsoft Graph as a fallback
                    if ($userName === '' && $userEmail !== '') {
                        try {
                            $graphService = new MicrosoftGraphService();
                            $entraUsers   = $graphService->searchUsers($userEmail);
                            foreach ($entraUsers as $eu) {
                                if (strcasecmp($eu['mail'] ?? '', $userEmail) === 0) {
                                    $userName = $eu['displayName'] ?? '';
                                    break;
                                }
                            }
                            if ($userName === '') {
                                error_log('checkoutItem: Entra user not found for email ' . $userEmail . ' – using email as fallback');
                            }
                        } catch (Exception $entraEx) {
                            error_log('checkoutItem: Entra lookup failed – ' . $entraEx->getMessage() . ' – using email as fallback');
                        }
                    }
                    if ($userName === '') {
                        $userName = $userEmail;
                    }
                }
            } catch (Exception $userEx) {
                error_log('checkoutItem: user lookup failed: ' . $userEx->getMessage());
            }

            self::evi()->assignItem((int)$itemId, (int)$userId, (int)$quantity, $note, $userName, $userEmail);

            // Record the rental in the new inventory database ($dbInventory).
            // Validate $start_date before passing it to createRental().
            $validStartDate = null;
            if ($start_date !== null && $start_date !== '') {
                try {
                    $validStartDate = (new DateTime($start_date))->format('Y-m-d');
                } catch (Exception $dateEx) {
                    error_log('checkoutItem: invalid start_date ignored: ' . $dateEx->getMessage());
                }
            }
            $rentalNotes = $note !== '' ? $note : null;
            $validEndDate = null;
            if ($expectedReturnDate !== null && $expectedReturnDate !== '') {
                try {
                    $validEndDate = (new DateTime($expectedReturnDate))->format('Y-m-d');
                } catch (Exception $endDateEx) {
                    error_log('checkoutItem: invalid expectedReturnDate ignored: ' . $endDateEx->getMessage());
                }
            }
            self::createRental(
                $itemId,
                (int)$userId,
                $validStartDate ?? date('Y-m-d'),
                $validEndDate,
                $rentalNotes
            );

            return ['success' => true, 'message' => 'Artikel erfolgreich ausgeliehen'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Fehler beim Ausleihen: ' . $e->getMessage()];
        }
    }

    /**
     * Create a rental record in the new inventory database ($dbInventory).
     *
     * Checks whether the logged-in user has an EasyVerein ID stored in the
     * users table (users.easyverein_id).
     *
     * - If the user HAS an EasyVerein ID: it is stored in
     *   inventory_rentals.easyverein_member_id.
     * - If the user has NO EasyVerein ID (e.g. new members or external helpers):
     *   the rental is still created successfully; easyverein_member_id is set to
     *   NULL and the intranet user_id is the only identifier stored.
     *
     * A log entry is written in both cases so that every transaction records
     * clearly which identity source was used.
     *
     * NOTE: This method ALWAYS uses \Database::getInventoryDB() ($dbInventory)
     * and never touches the old content database.
     *
     * @param int|string  $easyvereinItemId EasyVerein inventory-object ID
     * @param int         $userId           Local intranet user ID
     * @param string      $startDate        Rental start date (YYYY-MM-DD)
     * @param string|null $endDate          Expected return date (YYYY-MM-DD), or null
     * @param string|null $notes            Optional notes / purpose text
     * @return array ['success' => bool, 'message' => string, 'rental_id' => int|null]
     */
    public static function createRental($easyvereinItemId, int $userId, string $startDate, ?string $endDate = null, ?string $notes = null): array {
        try {
            // Always use the dedicated inventory database ($dbInventory) – never the content DB.
            $dbInventory = \Database::getInventoryDB();

            // Look up the item's local ID in the new inventory database by its EasyVerein ID.
            $itemStmt = $dbInventory->prepare(
                'SELECT id FROM inventory_items WHERE easyverein_item_id = ? LIMIT 1'
            );
            $itemStmt->execute([(string)$easyvereinItemId]);
            $itemRow = $itemStmt->fetch(PDO::FETCH_ASSOC);

            if (!$itemRow) {
                error_log(sprintf(
                    'createRental: item easyverein_item_id=%s not found in inventory DB – rental not recorded',
                    $easyvereinItemId
                ));
                return ['success' => false, 'message' => 'Artikel nicht in der Inventardatenbank gefunden', 'rental_id' => null];
            }
            $itemId = (int)$itemRow['id'];

            // Check whether the user has an EasyVerein ID stored in the users table.
            $easyvereinMemberId = null;
            try {
                $userDb   = \Database::getUserDB();
                $userStmt = $userDb->prepare('SELECT easyverein_id FROM users WHERE id = ? LIMIT 1');
                $userStmt->execute([$userId]);
                $userRow = $userStmt->fetch(PDO::FETCH_ASSOC);
                if ($userRow && !empty($userRow['easyverein_id'])) {
                    $easyvereinMemberId = $userRow['easyverein_id'];
                }
            } catch (Exception $userEx) {
                error_log('createRental: user lookup failed: ' . $userEx->getMessage());
            }

            // Log which identity source is used for this rental transaction.
            if ($easyvereinMemberId !== null) {
                error_log(sprintf(
                    'createRental [EasyVerein-User]: user_id=%d, easyverein_member_id=%s, item=%s',
                    $userId, $easyvereinMemberId, $easyvereinItemId
                ));
            } else {
                // Fallback: user has no EasyVerein ID – book the rental onto the central dummy user.
                if (!isset($userDb)) {
                    $userDb = \Database::getUserDB();
                }
                $dummyStmt = $userDb->prepare(
                    "SELECT id FROM users WHERE CONCAT(first_name, ' ', last_name) = ? LIMIT 1"
                );
                $dummyStmt->execute([INTRA_RENTAL_USER_NAME]);
                $dummyRow = $dummyStmt->fetch(PDO::FETCH_ASSOC);
                if (!$dummyRow) {
                    throw new Exception(sprintf(
                        'Der konfigurierte Ausleih-Dummy-User "%s" wurde in der Datenbank nicht gefunden. Bitte lege ihn im Intranet an.',
                        INTRA_RENTAL_USER_NAME
                    ));
                }
                $userId = (int)$dummyRow['id'];
                error_log(sprintf(
                    'createRental [Intranet-Fallback]: original user has no EasyVerein ID – booking onto dummy user_id=%d (%s), item=%s',
                    $userId, INTRA_RENTAL_USER_NAME, $easyvereinItemId
                ));
            }

            // Insert the rental record into the new inventory database ($dbInventory).
            $stmt = $dbInventory->prepare(
                'INSERT INTO inventory_rentals (item_id, user_id, easyverein_member_id, status, start_date, end_date, notes)
                 VALUES (?, ?, ?, \'active\', ?, ?, ?)'
            );
            $stmt->execute([$itemId, $userId, $easyvereinMemberId, $startDate, $endDate, $notes]);
            $rentalId = (int)$dbInventory->lastInsertId();

            return ['success' => true, 'message' => 'Ausleihe erfolgreich gespeichert', 'rental_id' => $rentalId];
        } catch (Exception $e) {
            // Log the failure and return a structured error; the rental record will not be created.
            error_log('createRental failed: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Fehler beim Erstellen der Ausleihe: ' . $e->getMessage(), 'rental_id' => null];
        }
    }

    /**
     * Check-in / return an item.
     *
     * Clears the member assignment in EasyVerein and restores the quantity.
     *
     * NOTE: The first parameter is the EasyVerein item ID (previously this
     * parameter was a local rental record ID – callers must be updated).
     *
     * @param int         $itemId            EasyVerein item ID
     * @param int         $returnedQuantity  Number of units being returned
     * @param bool        $isDefective       Whether any units are defective (informational only)
     * @param int         $defectiveQuantity Number of defective units (informational only)
     * @param string|null $defectiveReason   Description of defect (informational only)
     * @return array ['success' => bool, 'message' => string]
     */
    public static function checkinItem($itemId, $returnedQuantity, $isDefective = false, $defectiveQuantity = 0, $defectiveReason = null): array {
        if ((int)$returnedQuantity <= 0) {
            return ['success' => false, 'message' => 'Ungültige Rückgabemenge'];
        }

        try {
            self::evi()->returnItem((int)$itemId, (int)$returnedQuantity);
            return ['success' => true, 'message' => 'Artikel erfolgreich zurückgegeben'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Fehler bei der Rückgabe: ' . $e->getMessage()];
        }
    }

    // -------------------------------------------------------------------------
    // Dashboard / Statistics  (derived from EasyVerein data)
    // -------------------------------------------------------------------------

    public static function getDashboardStats(): array {
        $items = self::getAll();
        return [
            'total_items'  => count($items),
            'total_value'  => array_sum(array_map(fn($i) => $i['quantity'] * $i['unit_price'], $items)),
            'low_stock'    => count(array_filter($items, fn($i) => $i['available_quantity'] <= 0)),
            'recent_moves' => 0,
        ];
    }

    public static function getInStockStats(): array {
        $items = self::getAll();
        return [
            'total_in_stock'        => array_sum(array_column($items, 'quantity')),
            'unique_items_in_stock' => count(array_filter($items, fn($i) => $i['quantity'] > 0)),
            'total_value_in_stock'  => array_sum(array_map(fn($i) => $i['quantity'] * $i['unit_price'], $items)),
        ];
    }

    public static function getCheckedOutStats(): array {
        $items      = self::getAll();
        $checkedOut = array_filter($items, fn($i) => $i['loaned_count'] > 0);
        return [
            'total_items_out' => array_sum(array_column(array_values($checkedOut), 'loaned_count')),
            'unique_users'    => 0,
            'overdue'         => 0,
            'checkouts'       => [],
        ];
    }

    public static function getWriteOffStatsThisMonth(): array {
        return ['total_writeoffs' => 0, 'total_quantity_lost' => 0, 'writeoffs' => []];
    }

    // -------------------------------------------------------------------------
    // Categories / Locations  (derived from EasyVerein item data)
    // -------------------------------------------------------------------------

    public static function getCategories(): array {
        $seen       = [];
        $categories = [];
        foreach (self::getAll() as $item) {
            $name = $item['category_name'] ?? '';
            if ($name !== '' && !isset($seen[$name])) {
                $seen[$name]  = true;
                $categories[] = ['id' => $name, 'name' => $name, 'color' => '#3B82F6', 'description' => null];
            }
        }
        return $categories;
    }

    public static function getLocations(): array {
        $seen      = [];
        $locations = [];
        foreach (self::getAll() as $item) {
            $name = $item['location_name'] ?? '';
            if ($name !== '' && !isset($seen[$name])) {
                $seen[$name]  = true;
                $locations[]  = ['id' => $name, 'name' => $name, 'description' => null, 'address' => null];
            }
        }
        return $locations;
    }

    public static function getAllLocations(): array {
        return self::getLocations();
    }

    // -------------------------------------------------------------------------
    // Rental / checkout records  (no EasyVerein equivalent – return safe values)
    // -------------------------------------------------------------------------

    public static function getUserCheckouts($userId, $includeReturned = false): array { return []; }
    public static function getRentalsByUser($userId, $includeReturned = false): array { return []; }
    public static function getItemCheckouts($itemId): array { return []; }
    public static function getCheckoutById($rentalId): ?array { return null; }
    public static function getAllReturns($pendingOnly = false): array { return []; }

    /**
     * Submit a new inventory loan request with status 'pending'.
     *
     * Creates a record in inventory_requests so the board can approve or reject
     * it before the item is actually assigned to the member.
     *
     * @param string|int  $inventoryObjectId EasyVerein inventory-object ID
     * @param int         $userId            Requesting member's local user ID
     * @param string      $startDate         Requested start date (YYYY-MM-DD)
     * @param string      $endDate           Requested end date (YYYY-MM-DD)
     * @param int         $quantity          Number of units requested
     * @param string      $purpose           Reason / purpose of the loan
     * @return array ['success' => bool, 'message' => string, 'request_id' => int|null]
     */
    public static function submitRequest($inventoryObjectId, $userId, $startDate, $endDate, $quantity, $purpose): array {
        if ((int)$quantity <= 0) {
            return ['success' => false, 'message' => 'Ungültige Menge'];
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
            return ['success' => false, 'message' => 'Ungültiges Datumsformat. Erwartet: YYYY-MM-DD'];
        }

        if ($startDate > $endDate) {
            return ['success' => false, 'message' => 'Startdatum muss vor dem Enddatum liegen'];
        }

        try {
            $db   = \Database::getContentDB();
            $stmt = $db->prepare(
                "INSERT INTO inventory_requests
                    (inventory_object_id, user_id, start_date, end_date, quantity, purpose, status, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())"
            );
            $stmt->execute([
                (string)$inventoryObjectId,
                (int)$userId,
                $startDate,
                $endDate,
                (int)$quantity,
                $purpose !== '' ? $purpose : null,
            ]);
            return ['success' => true, 'message' => 'Anfrage erfolgreich eingereicht', 'request_id' => (int)$db->lastInsertId()];
        } catch (Exception $e) {
            error_log('Inventory::submitRequest failed: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Fehler beim Speichern der Anfrage: ' . $e->getMessage()];
        }
    }

    /**
     * Get all inventory requests with status 'pending', enriched with
     * the applicant's name/email and the EasyVerein item name.
     *
     * @return array Array of request rows with additional 'user_name', 'user_email', and 'item_name' keys.
     */
    public static function getPendingRequests(): array {
        try {
            $db   = \Database::getContentDB();
            $stmt = $db->query(
                "SELECT id, inventory_object_id, user_id, start_date, end_date, quantity, purpose, created_at
                 FROM inventory_requests
                 WHERE status = 'pending'
                 ORDER BY created_at ASC"
            );
            $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($requests)) {
                return [];
            }

            // Enrich with user name / email from the user database.
            $userIds = array_unique(array_column($requests, 'user_id'));
            $users   = [];
            try {
                $userDb       = \Database::getUserDB();
                $placeholders = implode(',', array_fill(0, count($userIds), '?'));
                $uStmt        = $userDb->prepare(
                    "SELECT id, email, first_name, last_name FROM users WHERE id IN ({$placeholders})"
                );
                $uStmt->execute($userIds);
                foreach ($uStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $users[(int)$row['id']] = $row;
                }
            } catch (Exception $ue) {
                error_log('getPendingRequests: user lookup failed: ' . $ue->getMessage());
            }

            // Enrich with EasyVerein item names.
            $itemNames = [];
            try {
                foreach (self::evi()->getItems() as $ev) {
                    $itemNames[(string)($ev['id'] ?? '')] = $ev['name'] ?? '';
                }
            } catch (Exception $ie) {
                error_log('getPendingRequests: EasyVerein item lookup failed: ' . $ie->getMessage());
            }

            foreach ($requests as &$request) {
                $user = $users[(int)$request['user_id']] ?? null;
                if ($user) {
                    $name                 = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
                    $request['user_name'] = $name !== '' ? $name : ($user['email'] ?? null);
                    $request['user_email'] = $user['email'] ?? null;
                } else {
                    $request['user_name']  = null;
                    $request['user_email'] = null;
                }
                $request['item_name'] = $itemNames[(string)$request['inventory_object_id']] ?? null;
            }
            unset($request);

            return $requests;
        } catch (Exception $e) {
            error_log('getPendingRequests failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get all rental records with status 'pending_return', enriched with
     * the borrower's name/email and the EasyVerein item name.
     *
     * @return array Array of rental rows with additional 'user_name', 'user_email', and 'item_name' keys.
     */
    public static function getPendingReturns(): array {
        try {
            $db   = \Database::getContentDB();
            $stmt = $db->query(
                "SELECT id, easyverein_item_id, user_id, quantity, rented_at FROM inventory_rentals WHERE status = 'pending_return' ORDER BY rented_at ASC"
            );
            $rentals = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($rentals)) {
                return [];
            }

            // Enrich with user name / email from the user database.
            $userIds = array_unique(array_column($rentals, 'user_id'));
            $users   = [];
            try {
                $userDb       = \Database::getUserDB();
                $placeholders = implode(',', array_fill(0, count($userIds), '?'));
                $uStmt        = $userDb->prepare(
                    "SELECT id, email, first_name, last_name FROM users WHERE id IN ({$placeholders})"
                );
                $uStmt->execute($userIds);
                foreach ($uStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $users[(int)$row['id']] = $row;
                }
            } catch (Exception $ue) {
                error_log('getPendingReturns: user lookup failed: ' . $ue->getMessage());
            }

            // Enrich with EasyVerein item names.
            $itemNames = [];
            try {
                foreach (self::evi()->getItems() as $ev) {
                    $itemNames[(string)($ev['id'] ?? '')] = $ev['name'] ?? '';
                }
            } catch (Exception $ie) {
                error_log('getPendingReturns: EasyVerein item lookup failed: ' . $ie->getMessage());
            }

            foreach ($rentals as &$rental) {
                $user = $users[(int)$rental['user_id']] ?? null;
                if ($user) {
                    $name                = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
                    $rental['user_name'] = $name !== '' ? $name : ($user['email'] ?? null);
                    $rental['user_email'] = $user['email'] ?? null;
                } else {
                    $rental['user_name']  = null;
                    $rental['user_email'] = null;
                }
                $rental['item_name'] = $itemNames[(string)$rental['easyverein_item_id']] ?? null;
            }
            unset($rental);

            return $rentals;
        } catch (Exception $e) {
            error_log('getPendingReturns failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Mark a rental as pending return.
     *
     * Sets the status of the given rental record to 'pending_return' so that an
     * administrator can review the item before the return is finalised.
     *
     * @param int $rentalId Local inventory_rentals.id
     * @return array ['success' => bool, 'message' => string]
     */
    public static function requestReturn($rentalId): array {
        try {
            $db   = \Database::getContentDB();

            // Fetch the rental first so we have the EasyVerein item ID for the sync call.
            $sel = $db->prepare(
                "SELECT easyverein_item_id FROM inventory_rentals WHERE id = ? AND status = 'active'"
            );
            $sel->execute([(int)$rentalId]);
            $rental = $sel->fetch(PDO::FETCH_ASSOC);

            if (!$rental) {
                return ['success' => false, 'message' => 'Ausleihe nicht gefunden oder bereits in Bearbeitung'];
            }

            $stmt = $db->prepare(
                "UPDATE inventory_rentals SET status = 'pending_return' WHERE id = ? AND status = 'active'"
            );
            $stmt->execute([(int)$rentalId]);
            if ($stmt->rowCount() === 0) {
                return ['success' => false, 'message' => 'Ausleihe nicht gefunden oder bereits in Bearbeitung'];
            }
            return ['success' => true, 'message' => 'Rückgabe angefragt – wartet auf Prüfung'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Fehler bei der Rückgabeanfrage: ' . $e->getMessage()];
        }
    }

    /**
     * Approve a pending return.
     *
     * Deletes the inventory_rentals record so the item is immediately available
     * again, and synchronises the return with EasyVerein: ends the active lending
     * and removes the borrower from custom fields.
     *
     * @param int    $rentalId  Local inventory_rentals.id
     * @param string $adminName Display name of the board member approving the return
     * @param string $notes     Optional remarks about the return
     * @return array ['success' => bool, 'message' => string]
     */
    public static function approveReturn($rentalId, $adminName = '', $notes = ''): array {
        try {
            $db = \Database::getContentDB();

            // Fetch the rental record so we know it exists and is pending.
            $stmt = $db->prepare(
                "SELECT * FROM inventory_rentals WHERE id = ? AND status = 'pending_return'"
            );
            $stmt->execute([(int)$rentalId]);
            $rental = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$rental) {
                return ['success' => false, 'message' => 'Ausleihe nicht gefunden oder nicht im Status "pending_return"'];
            }

            // Delete the rental record so the item is immediately available again.
            $del = $db->prepare("DELETE FROM inventory_rentals WHERE id = ?");
            $del->execute([(int)$rentalId]);

            // Synchronise the return with EasyVerein: end the active lending and
            // remove the borrower from custom fields.
            try {
                $returnUserName  = '';
                $returnUserEmail = '';
                try {
                    $userDb  = \Database::getUserDB();
                    $uStmt   = $userDb->prepare(
                        "SELECT first_name, last_name, email FROM users WHERE id = ? LIMIT 1"
                    );
                    $uStmt->execute([(int)$rental['user_id']]);
                    $uRow = $uStmt->fetch(PDO::FETCH_ASSOC);
                    if ($uRow) {
                        $returnUserEmail = $uRow['email'] ?? '';
                        $returnUserName  = trim(($uRow['first_name'] ?? '') . ' ' . ($uRow['last_name'] ?? ''));
                        // If no name is stored locally, try Microsoft Graph as a fallback
                        if ($returnUserName === '' && $returnUserEmail !== '') {
                            try {
                                $graphService = new MicrosoftGraphService();
                                $entraUsers   = $graphService->searchUsers($returnUserEmail);
                                foreach ($entraUsers as $eu) {
                                    if (strcasecmp($eu['mail'] ?? '', $returnUserEmail) === 0) {
                                        $returnUserName = $eu['displayName'] ?? '';
                                        break;
                                    }
                                }
                                if ($returnUserName === '') {
                                    error_log('approveReturn: Entra user not found for email ' . $returnUserEmail . ' – using email as fallback');
                                }
                            } catch (Exception $entraEx) {
                                error_log('approveReturn: Entra lookup failed – ' . $entraEx->getMessage() . ' – using email as fallback');
                            }
                        }
                        if ($returnUserName === '') {
                            $returnUserName = $returnUserEmail;
                        }
                    }
                } catch (Exception $userEx) {
                    error_log('approveReturn: failed to look up user info: ' . $userEx->getMessage());
                }
                self::evi()->verifyReturnForRental(
                    (int)$rental['easyverein_item_id'],
                    $adminName,
                    $returnUserName,
                    $returnUserEmail,
                    $notes
                );
            } catch (Exception $eviEx) {
                // Log but do not fail: the local DB record was already deleted.
                error_log('approveReturn: EasyVerein sync failed: ' . $eviEx->getMessage());
            }

            return ['success' => true, 'message' => 'Rückgabe bestätigt'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Fehler bei der Rückgabebestätigung: ' . $e->getMessage()];
        }
    }

    public static function confirmReturn($rentalId, $confirmingUserId): array {
        return ['success' => false, 'message' => 'Funktion nicht verfügbar'];
    }

    /**
     * Get all local rental records for an EasyVerein inventory item.
     *
     * Returns rows from inventory_rentals enriched with the borrower's e-mail
     * address (fetched from the user database).  The result is ordered newest-
     * first so callers can display a chronological checkout history.
     *
     * @param string|int $easyverein_item_id EasyVerein inventory-object ID
     * @return array Array of rental rows; each row contains all inventory_rentals
     *               columns plus 'user_email' from the users table.
     */
    public static function getRentalsByItem($easyverein_item_id): array {
        try {
            $db   = \Database::getContentDB();
            $stmt = $db->prepare(
                'SELECT * FROM inventory_rentals WHERE easyverein_item_id = ? ORDER BY rented_at DESC'
            );
            $stmt->execute([(string)$easyverein_item_id]);
            $rentals = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($rentals)) {
                return [];
            }

            // Enrich with user e-mail / name from the user database.
            $userIds = array_unique(array_column($rentals, 'user_id'));
            $users   = [];
            try {
                $userDb      = \Database::getUserDB();
                $placeholders = implode(',', array_fill(0, count($userIds), '?'));
                $uStmt       = $userDb->prepare(
                    "SELECT id, email FROM users WHERE id IN ({$placeholders})"
                );
                $uStmt->execute($userIds);
                foreach ($uStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $users[(int)$row['id']] = $row['email'];
                }
            } catch (Exception $ue) {
                error_log('getRentalsByItem: user lookup failed: ' . $ue->getMessage());
            }

            foreach ($rentals as &$rental) {
                $rental['user_email'] = $users[(int)$rental['user_id']] ?? null;
            }
            unset($rental);

            return $rentals;
        } catch (Exception $e) {
            error_log('getRentalsByItem failed: ' . $e->getMessage());
            return [];
        }
    }

    // -------------------------------------------------------------------------
    // Mutating operations – not applicable when EasyVerein is the master
    // -------------------------------------------------------------------------

    /** @return int|null Always null; item creation is managed in EasyVerein. */
    public static function create($data, $userId): ?int { return null; }

    /** @return bool Always false; item updates are managed in EasyVerein. */
    public static function update($id, $data, $userId, $isSyncUpdate = false): bool { return false; }

    /** @return bool Always false; item deletion is managed in EasyVerein. */
    public static function delete($id, $userId = null): bool { return false; }

    /** @return bool Always false; stock adjustments are managed in EasyVerein. */
    public static function adjustStock($id, $amount, $reason, $comment, $userId): bool { return false; }

    /** No-op: history logging is no longer stored locally. */
    public static function logHistory($itemId, $userId, $changeType, $oldStock, $newStock, $changeAmount, $reason, $comment): void {}

    /** @return array Always empty; history is no longer stored locally. */
    public static function getHistory($itemId, $limit = 50): array { return []; }

    /** @return int|null Always null; categories are managed in EasyVerein. */
    public static function createCategory($name, $description = null, $color = '#3B82F6'): ?int { return null; }

    /** @return int|null Always null; locations are managed in EasyVerein. */
    public static function createLocation($name, $description = null, $address = null): ?int { return null; }

    /** Import is not supported when EasyVerein is the master data source. */
    public static function importFromJson($data, $userId): array {
        return ['success' => false, 'imported' => 0, 'skipped' => 0, 'errors' => ['Import nicht verfügbar – EasyVerein ist die Datenquelle']];
    }

    // -------------------------------------------------------------------------
    // Sync
    // -------------------------------------------------------------------------

    /**
     * Sync inventory from EasyVerein.
     *
     * @param int $userId User ID performing the sync (for audit trail)
     * @return array Result with statistics (created, updated, archived, errors)
     */
    public static function syncFromEasyVerein($userId): array {
        $sync = new \App\Services\EasyVereinSync();
        return $sync->sync($userId);
    }
}

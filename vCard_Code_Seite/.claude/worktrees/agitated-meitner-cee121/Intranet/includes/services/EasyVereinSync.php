<?php
/**
 * EasyVereinSync Service
 * Handles one-way synchronization from EasyVerein (External) to Intranet (Local)
 */

require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../models/Inventory.php';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../src/MailService.php';

class EasyVereinSync {

    /**
     * Resolve the EasyVerein API token from DB or constant.
     *
     * Priority order:
     *   1. system_settings DB table (key: easyverein_api_token) – updated by token refresh
     *   2. EASYVEREIN_API_TOKEN constant sourced from .env / config
     *
     * @throws Exception If no API token can be found.
     */
    private static function resolveApiToken(): string {
        // 1. Check DB system_settings for a previously refreshed or manually saved token
        try {
            $db   = Database::getContentDB();
            $stmt = $db->prepare(
                "SELECT setting_value FROM system_settings WHERE setting_key = 'easyverein_api_token' LIMIT 1"
            );
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && !empty($row['setting_value'])) {
                return $row['setting_value'];
            }
        } catch (Exception $e) {
            // DB unavailable – fall through to constant
        }

        // 2. Constant from .env / config
        $token = defined('EASYVEREIN_API_TOKEN') ? EASYVEREIN_API_TOKEN : '';
        if (empty($token)) {
            throw new Exception(
                'EasyVerein API-Token nicht konfiguriert. ' .
                'Bitte den Token in den Systemeinstellungen (Schlüssel: easyverein_api_token) oder in der .env-Datei hinterlegen.'
            );
        }
        return $token;
    }

    /** Instance wrapper around the static token resolver (for use in non-static methods). */
    private function getApiToken(): string {
        return self::resolveApiToken();
    }

    /**
     * Fetch data from EasyVerein API
     * 
     * @return array Array of inventory items from EasyVerein API
     * @throws Exception If API call fails
     */
    public function fetchDataFromEasyVerein() {
        $primaryUrl  = 'https://easyverein.com/api/v3.0/inventory-object?limit=100';
        $fallbackUrl = 'https://easyverein.com/api/v2.0/inventory-object?limit=100';

        $apiToken = $this->getApiToken();

        try {
            $headers = [
                'Authorization: Bearer ' . $apiToken,
                'Content-Type: application/json',
                'Accept: application/json',
            ];

            // Helper closure to execute a single GET request
            $doRequest = function (string $url) use ($headers): array {
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
                $response  = curl_exec($ch);
                $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                curl_close($ch);
                return [$response, $httpCode, $curlError];
            };

            // First attempt: primary URL (v3.0)
            [$response, $httpCode, $curlError] = $doRequest($primaryUrl);

            if ($response === false) {
                throw new Exception('Netzwerkfehler: ' . $curlError);
            }

            // On 404, try fallback URL (v2.0)
            if ($httpCode === 404) {
                [$response, $httpCode, $curlError] = $doRequest($fallbackUrl);

                if ($response === false) {
                    throw new Exception('Netzwerkfehler: ' . $curlError);
                }
            }

            // Check HTTP status code with detailed German error messages
            if ($httpCode !== 200) {
                $errorMsg = "EasyVerein API: HTTP {$httpCode}";
                if ($httpCode === 401) {
                    $errorMsg .= ' – Nicht autorisiert. Der API-Token ist ungültig oder abgelaufen. '
                        . 'Bitte den Token in den Systemeinstellungen aktualisieren.';
                } elseif ($httpCode === 403) {
                    $errorMsg .= ' – Zugriff verweigert. Dem API-Token fehlen die nötigen Berechtigungen. '
                        . 'Bitte sicherstellen, dass das Modul [Inventar] auf [Lesen] gesetzt ist.';
                } elseif ($httpCode === 404) {
                    $errorMsg .= ' – Endpunkt nicht gefunden. Der API-Endpunkt /inventory-object existiert nicht. '
                        . 'Mögliche Ursachen: (1) Das Inventar-Modul ist im easyVerein-Account nicht freigeschaltet. '
                        . '(2) Die API-Versionen v3.0 und v2.0 werden von diesem Account nicht unterstützt. '
                        . 'Bitte im easyVerein-Adminbereich unter Einstellungen → API prüfen.';
                } elseif ($httpCode === 429) {
                    $errorMsg .= ' – Zu viele Anfragen (Rate Limit). Bitte etwas warten und es erneut versuchen.';
                } elseif ($httpCode >= 500) {
                    $errorMsg .= ' – EasyVerein-Server-Fehler. Bitte später erneut versuchen.';
                }
                throw new Exception($errorMsg);
            }
            
            // Parse JSON response
            $data = json_decode($response, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Ungültige API-Antwort (kein gültiges JSON): ' . json_last_error_msg());
            }
            
            // EasyVerein API typically returns data in a wrapper
            // Adjust based on actual API response structure
            $items = $data['results'] ?? $data['data'] ?? $data;
            
            if (!is_array($items)) {
                throw new Exception('Unerwartetes API-Antwortformat: Es wurde ein Array erwartet.');
            }
            
            return $items;
            
        } catch (Exception $e) {
            // Log the error
            error_log('EasyVerein API Error: ' . $e->getMessage());
            
            // Send critical alert email
            $this->sendCriticalAlert($e->getMessage());
            
            // Re-throw the exception
            throw $e;
        }
    }
    
    /**
     * Send critical alert email when API sync fails
     * 
     * @param string $errorMessage The error message to include in email
     */
    private function sendCriticalAlert($errorMessage) {
        $subject = 'CRITICAL: EasyVerein Sync Failed';
        
        $bodyContent = '<p class="email-text">The EasyVerein API synchronization has failed.</p>';
        $bodyContent .= '<p class="email-text"><strong>Error Details:</strong></p>';
        $bodyContent .= '<div style="background-color: #fee; padding: 15px; border-left: 4px solid #c00; margin: 15px 0;">';
        $bodyContent .= '<pre style="margin: 0; font-family: monospace; white-space: pre-wrap;">' . htmlspecialchars($errorMessage) . '</pre>';
        $bodyContent .= '</div>';
        $bodyContent .= '<p class="email-text">Time: ' . date('Y-m-d H:i:s') . '</p>';
        $bodyContent .= '<p class="email-text">Please investigate and resolve this issue as soon as possible.</p>';
        
        // Get email template
        $htmlBody = MailService::getTemplate('EasyVerein Sync Failure', $bodyContent);
        
        // Send email
        try {
            MailService::sendEmail(defined('INVENTORY_BOARD_EMAIL') ? INVENTORY_BOARD_EMAIL : SMTP_FROM, $subject, $htmlBody);
        } catch (Exception $e) {
            error_log('Failed to send critical alert email: ' . $e->getMessage());
        }
    }
    
    /**
     * Extract image URL from EasyVerein API item data
     * 
     * @param array $evItem EasyVerein API item data
     * @return string|null EasyVerein image URL or null if no image
     */
    private function extractImageUrl($evItem) {
        // Check for image in various possible field names
        $imageUrl = null;
        
        // Check 'picture' field first (as per requirements)
        if (isset($evItem['picture']) && !empty($evItem['picture'])) {
            $imageUrl = $evItem['picture'];
        }
        // Check common field names for image
        elseif (isset($evItem['image']) && !empty($evItem['image'])) {
            $imageUrl = $evItem['image'];
        } elseif (isset($evItem['avatar']) && !empty($evItem['avatar'])) {
            $imageUrl = $evItem['avatar'];
        } elseif (isset($evItem['image_path']) && !empty($evItem['image_path'])) {
            $imageUrl = $evItem['image_path'];
        } elseif (isset($evItem['image_url']) && !empty($evItem['image_url'])) {
            $imageUrl = $evItem['image_url'];
        }
        
        // Return the EasyVerein URL directly (not downloaded)
        return $imageUrl;
    }
    
    /**
     * Synchronize inventory from EasyVerein to local database
     * 
     * This method:
     * 1. Fetches data from EasyVerein
     * 2. For each item:
     *    - If exists locally (by easyverein_id): Updates master data fields
     *    - If not exists: Creates new inventory record
     * 3. For deletions: Marks items with easyverein_id not in fetch result as archived
     * 
     * @param int $userId User ID performing the sync (for audit trail)
     * @return array Result with statistics (created, updated, archived, errors)
     */
    public function sync($userId = null) {
        $db = Database::getContentDB();
        
        // If no userId provided, use system user (0)
        if ($userId === null) {
            $userId = 0;
        }
        
        $stats = [
            'created' => 0,
            'updated' => 0,
            'archived' => 0,
            'errors' => []
        ];
        
        try {
            // Fetch data from EasyVerein
            $easyvereinItems = $this->fetchDataFromEasyVerein();
            
            // Track EasyVerein IDs that are present in this sync
            $currentEasyVereinIds = [];
            
            // Process each item from EasyVerein
            foreach ($easyvereinItems as $evItem) {
                try {
                    // Map API fields to our expected format
                    // Map: name -> name, note -> description, pieces -> quantity (DB: quantity), 
                    // acquisitionPrice -> unit_price, locationName -> location (for future mapping), picture -> image_path
                    $easyvereinId = $evItem['id'] ?? $evItem['EasyVereinID'] ?? null;
                    $name = $evItem['name'] ?? $evItem['Name'] ?? 'Unnamed Item';
                    $description = $evItem['note'] ?? $evItem['description'] ?? $evItem['Description'] ?? '';
                    $totalQuantity = (int)($evItem['pieces'] ?? $evItem['quantity'] ?? 0);
                    // Use acquisition_price as primary (v3.0 snake_case), with camelCase and other fallbacks
                    $unitPrice = $evItem['acquisition_price'] ?? $evItem['acquisitionPrice'] ?? $evItem['price'] ?? $evItem['unit_price'] ?? 0;
                    $serialNumber = $evItem['serial_number'] ?? $evItem['SerialNumber'] ?? null;
                    // Extract location name (v3.0 snake_case first, then camelCase fallback)
                    $locationName = $evItem['location_name'] ?? $evItem['locationName'] ?? $evItem['location'] ?? null;
                    
                    // Extract image URL (do NOT download - save URL directly)
                    $imageUrl = $this->extractImageUrl($evItem);
                    
                    if (!$easyvereinId) {
                        $stats['errors'][] = "Skipping item without ID: " . ($name ?? 'Unknown');
                        continue;
                    }
                    
                    $currentEasyVereinIds[] = $easyvereinId;
                    
                    // Check if item exists locally by easyverein_id
                    $stmt = $db->prepare("
                        SELECT id, name, description, quantity, serial_number
                        FROM inventory_items
                        WHERE easyverein_id = ?
                    ");
                    $stmt->execute([$easyvereinId]);
                    $existingItem = $stmt->fetch();
                    
                    if ($existingItem) {
                        // Update existing item using Inventory model with sync flag
                        // This allows the update to bypass Master Data protection
                        $updateData = [
                            'name' => $name,
                            'description' => $description,
                            'quantity' => $totalQuantity,
                            'unit_price' => $unitPrice,
                            'serial_number' => $serialNumber,
                            'is_archived_in_easyverein' => 0
                        ];
                        
                        // Always update image URL if provided (EasyVerein URL, not downloaded)
                        if ($imageUrl) {
                            $updateData['image_path'] = $imageUrl;
                        }
                        
                        // Use Inventory::update() with $isSyncUpdate = true to bypass protection
                        Inventory::update($existingItem['id'], $updateData, $userId, true);
                        
                        // Update last_synced_at separately using MySQL NOW() for timezone consistency
                        $stmt = $db->prepare("UPDATE inventory_items SET last_synced_at = NOW() WHERE id = ?");
                        $stmt->execute([$existingItem['id']]);
                        
                        $stats['updated']++;
                        
                        // Log update in history
                        $this->logSyncHistory(
                            $existingItem['id'],
                            $userId,
                            'sync_update',
                            $existingItem['quantity'],
                            $totalQuantity,
                            'Synchronized from EasyVerein',
                            json_encode([
                                'old_name' => $existingItem['name'],
                                'new_name' => $name,
                                'easyverein_id' => $easyvereinId
                            ])
                        );
                        
                    } else {
                        // Create new item with explicit field list for security
                        $stmt = $db->prepare("
                            INSERT INTO inventory_items (
                                easyverein_id,
                                name,
                                description,
                                serial_number,
                                quantity,
                                unit_price,
                                image_path,
                                is_archived_in_easyverein,
                                last_synced_at
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, 0, NOW())
                        ");
                        
                        $stmt->execute([
                            $easyvereinId,
                            $name,
                            $description,
                            $serialNumber,
                            $totalQuantity,
                            $unitPrice,
                            $imageUrl
                        ]);
                        
                        $newItemId = $db->lastInsertId();
                        $stats['created']++;
                        
                        // Log creation in history
                        $this->logSyncHistory(
                            $newItemId,
                            $userId,
                            'sync_create',
                            null,
                            $totalQuantity,
                            'Created from EasyVerein sync',
                            json_encode([
                                'easyverein_id' => $easyvereinId,
                                'name' => $name
                            ])
                        );
                    }
                    
                } catch (Exception $e) {
                    $stats['errors'][] = "Error processing item '" . ($name ?? 'Unknown') . "' (EV-ID: " . ($easyvereinId ?? 'N/A') . "): " . $e->getMessage();
                }
            }
            
            // Handle deletions: Mark items with easyverein_id NOT in current fetch as archived
            // This should run even if currentEasyVereinIds is empty (i.e., EasyVerein returns no items)
            if (!empty($currentEasyVereinIds)) {
                $placeholders = str_repeat('?,', count($currentEasyVereinIds) - 1) . '?';
                
                // Find items with easyverein_id that are not in the current sync
                $stmt = $db->prepare("
                    SELECT id, easyverein_id, name
                    FROM inventory_items
                    WHERE easyverein_id IS NOT NULL
                    AND easyverein_id NOT IN ($placeholders)
                    AND is_archived_in_easyverein = 0
                ");
                $stmt->execute($currentEasyVereinIds);
                $itemsToArchive = $stmt->fetchAll();
            } else {
                // If EasyVerein returns no items, archive all items with easyverein_id
                $stmt = $db->prepare("
                    SELECT id, easyverein_id, name
                    FROM inventory_items
                    WHERE easyverein_id IS NOT NULL
                    AND is_archived_in_easyverein = 0
                ");
                $stmt->execute();
                $itemsToArchive = $stmt->fetchAll();
            }
            
            // Archive items not found in current sync
            foreach ($itemsToArchive as $item) {
                // Mark as archived (soft delete)
                $stmt = $db->prepare("
                    UPDATE inventory_items
                    SET is_archived_in_easyverein = 1,
                        last_synced_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$item['id']]);
                
                $stats['archived']++;
                
                // Log archival
                $this->logSyncHistory(
                    $item['id'],
                    $userId,
                    'sync_archive',
                    null,
                    null,
                    'Archived - no longer in EasyVerein',
                    json_encode([
                        'easyverein_id' => $item['easyverein_id'],
                        'name' => $item['name']
                    ])
                );
            }
            
        } catch (Exception $e) {
            $stats['errors'][] = "Sync failed: " . $e->getMessage();
        }
        
        return $stats;
    }
    
    /**
     * Log synchronization history
     * 
     * @param int $itemId Inventory item ID
     * @param int $userId User ID performing the action
     * @param string $changeType Type of change (sync_create, sync_update, sync_archive)
     * @param mixed $oldStock Old stock value
     * @param mixed $newStock New stock value
     * @param string $reason Reason for the change
     * @param string $comment Additional comment/data
     */
    private function logSyncHistory($itemId, $userId, $changeType, $oldStock, $newStock, $reason, $comment) {
        $db = Database::getContentDB();
        
        $stmt = $db->prepare("
            INSERT INTO inventory_history (
                item_id,
                user_id,
                change_type,
                old_stock,
                new_stock,
                change_amount,
                reason,
                comment
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $changeAmount = null;
        if ($oldStock !== null && $newStock !== null) {
            $changeAmount = $newStock - $oldStock;
        }
        
        $stmt->execute([
            $itemId,
            $userId,
            $changeType,
            $oldStock,
            $newStock,
            $changeAmount,
            $reason,
            $comment
        ]);
    }
    
    /**
     * Trigger bank account synchronization via EasyVerein / FinAPI
     *
     * Sends a request to the EasyVerein API to initiate a manual refresh of
     * connected bank accounts (FinAPI). EasyVerein processes this asynchronously,
     * so a successful response only confirms that the trigger was accepted.
     *
     * @return array Result with success status and any error or info messages
     */
    public function triggerBankSync() {
        try {
            $apiToken = $this->getApiToken();
        } catch (Exception $e) {
            error_log('EasyVerein triggerBankSync: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }

        // EasyVerein exposes bank connections under v1.6; triggering a refresh
        // is done by POSTing to the bank-connections update endpoint.
        // The operation is asynchronous – a 2xx response means it was accepted.
        $apiUrl = 'https://easyverein.com/api/v1.6/bank-connections/refresh/';

        try {
            $ch = curl_init();

            curl_setopt($ch, CURLOPT_URL, $apiUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([]));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $apiToken,
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($response === false) {
                throw new Exception('cURL error: ' . $curlError);
            }

            // 2xx codes indicate the trigger was accepted (processing is async)
            if ($httpCode >= 200 && $httpCode < 300) {
                error_log('EasyVerein triggerBankSync: Bank sync triggered successfully (HTTP ' . $httpCode . '). Processing is asynchronous.');
                return [
                    'success' => true,
                    'message' => 'Bank sync triggered successfully. Processing is asynchronous.',
                    'http_code' => $httpCode
                ];
            }

            $errorMsg = "EasyVerein API (Bank-Sync): HTTP {$httpCode}";
            if ($httpCode === 401) {
                $errorMsg .= ' – Nicht autorisiert. Der API-Token ist ungültig oder abgelaufen.';
            } elseif ($httpCode === 404) {
                $errorMsg .= ' – Bank-Sync-Endpunkt nicht gefunden. '
                    . 'Das manuelle Auslösen der Bank-Synchronisierung wird von dieser API-Version möglicherweise nicht unterstützt. '
                    . 'EasyVerein führt Bank-Syncs normalerweise automatisch im Hintergrund durch.';
                error_log('EasyVerein triggerBankSync: ' . $errorMsg);
            }
            throw new Exception($errorMsg);

        } catch (Exception $e) {
            error_log('EasyVerein triggerBankSync Error: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Fetch bank transactions from EasyVerein for the last X days
     *
     * Retrieves all bank transactions via /api/v1.6/bank-transactions/ filtered
     * to the given number of days back from today.
     *
     * @param int $days Number of days to look back (default: 7)
     * @return array Array of bank transaction objects returned by the API
     * @throws Exception If the API call fails
     */
    public static function getBankTransactions($days = 7) {
        $apiToken = self::resolveApiToken();

        $days = max(1, (int)$days);
        $dateFrom = date('Y-m-d', strtotime("-{$days} days"));

        // Build URL with date filter; EasyVerein uses `date_gte` query param for this endpoint
        $apiUrl = 'https://live.easyverein.com/api/v1.6/bank-transactions/?date_gte=' . urlencode($dateFrom) . '&limit=100';

        try {
            $transactions = [];

            // Paginate through all results
            while ($apiUrl !== null) {
                $ch = curl_init();

                curl_setopt($ch, CURLOPT_URL, $apiUrl);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Authorization: Token ' . $apiToken,
                    'Content-Type: application/json'
                ]);
                curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                curl_close($ch);

                if ($response === false) {
                    throw new Exception('cURL error: ' . $curlError);
                }

                if ($httpCode !== 200) {
                    $errorMsg = "API returned HTTP {$httpCode}";
                    if ($httpCode === 401) {
                        $errorMsg .= ' - Unauthorized: Invalid API token';
                    }
                    throw new Exception($errorMsg);
                }

                $data = json_decode($response, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new Exception('Failed to parse JSON response: ' . json_last_error_msg());
                }

                if (!is_array($data)) {
                    throw new Exception('Invalid API response format: expected JSON object or array');
                }

                $page = $data['results'] ?? $data['data'] ?? $data;

                if (!is_array($page)) {
                    throw new Exception('Invalid API response format: expected array of transactions');
                }

                $transactions = array_merge($transactions, $page);

                // Follow pagination if a next URL is provided
                $apiUrl = isset($data['next']) && is_string($data['next']) ? $data['next'] : null;
            }

            return $transactions;

        } catch (Exception $e) {
            error_log('EasyVerein getBankTransactions Error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Mark an invoice as paid in EasyVerein
     *
     * Sends a PATCH request to EasyVerein to set the payment status of the
     * given invoice document to "paid".
     *
     * @param int|string $easyvereinInvoiceId EasyVerein invoice / billing document ID
     * @return array Result with success status and any error messages
     */
    public function markInvoiceAsPaidInEV($easyvereinInvoiceId) {
        try {
            $apiToken = $this->getApiToken();
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }

        if (empty($easyvereinInvoiceId)) {
            return ['success' => false, 'error' => 'Ungültige Rechnungs-ID'];
        }

        $apiUrl = 'https://easyverein.com/api/v1.6/invoices/' . urlencode($easyvereinInvoiceId) . '/';

        try {
            $ch = curl_init();

            curl_setopt($ch, CURLOPT_URL, $apiUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['isPaid' => true]));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $apiToken,
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($response === false) {
                throw new Exception('cURL error: ' . $curlError);
            }

            // 200 or 204 indicate a successful PATCH
            if ($httpCode === 200 || $httpCode === 204) {
                return [
                    'success' => true,
                    'message' => 'Invoice ' . $easyvereinInvoiceId . ' marked as paid in EasyVerein'
                ];
            }

            $errorMsg = "EasyVerein API (Rechnung): HTTP {$httpCode}";
            if ($httpCode === 401) {
                $errorMsg .= ' – Nicht autorisiert. Der API-Token ist ungültig.';
            } elseif ($httpCode === 404) {
                $errorMsg .= ' – Rechnung mit ID ' . $easyvereinInvoiceId . ' nicht in EasyVerein gefunden.';
            }
            throw new Exception($errorMsg);

        } catch (Exception $e) {
            error_log('EasyVerein markInvoiceAsPaidInEV Error (Invoice ID: ' . $easyvereinInvoiceId . '): ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Update item in EasyVerein (Write-Back)
     * 
     * Sends a PATCH request to EasyVerein API to update an inventory item
     * 
     * @param int $easyvereinId EasyVerein item ID
     * @param array $data Fields to update (name, quantity, note/description)
     * @return array Result with success status and any error messages
     */
    public static function updateItem($easyvereinId, $data) {
        $apiUrl   = "https://easyverein.com/api/v3.0/inventory-object/{$easyvereinId}";
        $apiToken = self::resolveApiToken();
        
        try {
            // Map our fields to EasyVerein API fields
            $apiData = [];
            if (isset($data['name'])) {
                $apiData['name'] = $data['name'];
            }
            if (isset($data['quantity'])) {
                $apiData['pieces'] = $data['quantity'];
            }
            if (isset($data['description'])) {
                $apiData['note'] = $data['description'];
            }
            if (isset($data['unit_price'])) {
                $apiData['price'] = $data['unit_price'];
            }
            
            // Initialize cURL
            $ch = curl_init();
            
            // Set cURL options for PATCH request
            curl_setopt($ch, CURLOPT_URL, $apiUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $apiToken,
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($apiData));
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            
            // Execute the request
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            
            // Check for cURL errors
            if ($response === false) {
                throw new Exception('cURL error: ' . $curlError);
            }
            
            // Check HTTP status code (200 or 204 for successful PATCH)
            if ($httpCode !== 200 && $httpCode !== 204) {
                $errorMsg = "EasyVerein API (Update Artikel): HTTP {$httpCode}";
                if ($httpCode === 401) {
                    $errorMsg .= ' – Nicht autorisiert. Der API-Token ist ungültig.';
                } elseif ($httpCode === 404) {
                    $errorMsg .= ' – Artikel (EV-ID: ' . $easyvereinId . ') nicht in EasyVerein gefunden.';
                }
                throw new Exception($errorMsg);
            }
            
            return [
                'success' => true,
                'message' => 'Artikel in EasyVerein erfolgreich aktualisiert'
            ];
            
        } catch (Exception $e) {
            // Log the error
            error_log('EasyVerein API Update Error (ID: ' . $easyvereinId . '): ' . $e->getMessage());
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}

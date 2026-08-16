<?php
/**
 * Bank Payment Reconciliation Cron Script
 *
 * Automatically reconciles pending Vorkasse (bank transfer) shop invoices against
 * EasyVerein bank transactions. Marks matched invoices as paid and escalates
 * overdue invoices (pending > 14 days) to the board finance team.
 *
 * Crontab example (daily at 06:00):
 * 0 6 * * * /usr/bin/php /path/to/cron/reconcile_bank_payments.php >> /path/to/logs/reconcile_bank_payments.log 2>&1
 *
 * Usage: php cron/reconcile_bank_payments.php
 */

// Load required files
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/services/EasyVereinSync.php';
require_once __DIR__ . '/../src/MailService.php';

if (PHP_SAPI !== 'cli') {
    if (empty($_ENV['CRON_TOKEN']) || !is_string($_ENV['CRON_TOKEN']) || strlen($_ENV['CRON_TOKEN']) < 16) {
        http_response_code(500);
        exit('CRON_TOKEN not configured securely');
    }
    $__cronToken = CRON_TOKEN;
    if ($__cronToken === '' || !isset($_GET['token']) || !is_string($_GET['token']) || !hash_equals($__cronToken, $_GET['token'])) {
        http_response_code(403);
        exit('Forbidden.' . PHP_EOL);
    }
    unset($__cronToken);
}

echo "=== Bank Payment Reconciliation ===\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n\n";

// Get database connections for logging and invoice lookups
$rechDb   = Database::getRechDB();
$userDb   = Database::getUserDB();
$contentDb = Database::getContentDB();

// Log cron execution start
try {
    $stmt = $contentDb->prepare("INSERT INTO system_logs (user_id, action, entity_type, entity_id, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([0, 'cron_reconcile_bank_payments', 'cron', null, 'Started reconciliation', 'cron', 'cron']);
} catch (Exception $e) {
    // Ignore logging errors
}

try {
    $syncService = new EasyVereinSync();

    // 1. Trigger bank account synchronization so recent transactions are loaded
    echo "Triggering bank sync...\n";
    $triggerResult = $syncService->triggerBankSync();
    if (!$triggerResult['success']) {
        echo "Warning: Bank sync trigger failed: " . ($triggerResult['error'] ?? 'unknown error') . "\n";
    } else {
        echo "Bank sync triggered: " . ($triggerResult['message'] ?? 'OK') . "\n";
    }

    // 2. Wait briefly so EasyVerein can process the asynchronous bank update
    echo "Waiting 5 seconds for transactions to load...\n";
    sleep(5);

    // 3. Fetch bank transactions (look back 30 days to cover oldest escalation window)
    echo "Fetching bank transactions...\n";
    $transactions = EasyVereinSync::getBankTransactions(30);
    echo "Fetched " . count($transactions) . " bank transaction(s).\n\n";

    // 4. Load all pending bank-transfer invoices
    $stmt = $rechDb->prepare("
        SELECT id, description, amount, payment_purpose, easyverein_document_id, created_at
        FROM invoices
        WHERE status = 'pending'
          AND payment_method = 'bank_transfer'
        ORDER BY created_at ASC
    ");
    $stmt->execute();
    $pendingInvoices = $stmt->fetchAll();

    echo "Found " . count($pendingInvoices) . " pending bank-transfer invoice(s).\n\n";

    $matched   = 0;
    $escalated = 0;

    // 5. Iterate through invoices and match against bank transactions
    foreach ($pendingInvoices as $invoice) {
        $invoiceId      = (int) $invoice['id'];
        $paymentPurpose = $invoice['payment_purpose'];
        $amount         = $invoice['amount'];
        $description    = $invoice['description'];
        $evDocId        = $invoice['easyverein_document_id'];
        $createdAt      = new DateTime($invoice['created_at']);
        $now            = new DateTime();
        $daysPending    = (int) $now->diff($createdAt)->days;

        echo "Checking invoice #{$invoiceId} ({$description}) – purpose: {$paymentPurpose} – pending {$daysPending} day(s)...\n";

        // Check if payment_purpose appears in any bank transaction's purpose or reference
        $paymentFound = false;
        foreach ($transactions as $tx) {
            $txPurpose   = (string) ($tx['purpose']   ?? '');
            $txReference = (string) ($tx['reference'] ?? '');
            $txInfo      = (string) ($tx['info']      ?? '');

            if (
                ($txPurpose   !== '' && stripos($txPurpose,   $paymentPurpose) !== false) ||
                ($txReference !== '' && stripos($txReference, $paymentPurpose) !== false) ||
                ($txInfo      !== '' && stripos($txInfo,      $paymentPurpose) !== false)
            ) {
                $paymentFound = true;
                break;
            }
        }

        if ($paymentFound) {
            echo "  -> Match found! Marking invoice #{$invoiceId} as paid.\n";

            // Mark invoice as paid in local DB
            $updateStmt = $rechDb->prepare("
                UPDATE invoices
                SET status = 'paid', paid_at = NOW(), paid_by_user_id = 0, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $updateStmt->execute([$invoiceId]);

            // Mark invoice as paid in EasyVerein (non-blocking)
            if (!empty($evDocId)) {
                $evResult = $syncService->markInvoiceAsPaidInEV($evDocId);
                if (!$evResult['success']) {
                    echo "  Warning: markInvoiceAsPaidInEV failed for EV document {$evDocId}: " . ($evResult['error'] ?? 'unknown') . "\n";
                }
            }

            $matched++;

            // Log the payment match
            try {
                $logDetails = "Matched invoice #{$invoiceId} ({$paymentPurpose}) against bank transaction";
                $logStmt = $contentDb->prepare("INSERT INTO system_logs (user_id, action, entity_type, entity_id, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $logStmt->execute([0, 'cron_bank_payment_matched', 'invoice', $invoiceId, $logDetails, 'cron', 'cron']);
            } catch (Exception $e) {
                // Ignore logging errors
            }

        } elseif ($daysPending > 14) {
            // 6. Escalation: invoice pending for more than 14 days without a matching transaction
            echo "  -> No match found and pending > 14 days. Escalating to vorstand_finanzen...\n";

            // Find all users with role 'vorstand_finanzen'
            $boardStmt = $userDb->prepare("SELECT id, email FROM users WHERE role = 'vorstand_finanzen'");
            $boardStmt->execute();
            $boardUsers = $boardStmt->fetchAll();

            if (empty($boardUsers)) {
                echo "  Warning: No users with role vorstand_finanzen found for escalation.\n";
            }

            $amountFormatted = number_format((float) $amount, 2, ',', '.');
            $subject = 'Achtung: Offene Vorkasse-Zahlung für Shop-Rechnung #' . $invoiceId;
            $bodyText = 'Achtung: Zur Shop-Rechnung #' . $invoiceId . ' über ' . $amountFormatted . '€'
                . ' mit dem Verwendungszweck ' . $paymentPurpose
                . ' konnte auch nach 14 Tagen keine Überweisung auf dem Vereinskonto zugeordnet werden.'
                . ' Bitte manuell prüfen.';

            $bodyContent  = '<p class="email-text">' . htmlspecialchars($bodyText) . '</p>';
            $htmlBody     = MailService::getTemplate('Offene Vorkasse-Zahlung', $bodyContent);

            foreach ($boardUsers as $boardUser) {
                if (empty($boardUser['email'])) {
                    continue;
                }
                try {
                    MailService::sendEmail($boardUser['email'], $subject, $htmlBody);
                    echo "  Escalation email sent to: " . $boardUser['email'] . "\n";
                } catch (Exception $mailEx) {
                    echo "  Warning: Failed to send escalation email to " . $boardUser['email'] . ": " . $mailEx->getMessage() . "\n";
                }
            }

            $escalated++;

            // Log the escalation
            try {
                $logDetails = "Escalated invoice #{$invoiceId} ({$paymentPurpose}) – pending {$daysPending} days";
                $logStmt = $contentDb->prepare("INSERT INTO system_logs (user_id, action, entity_type, entity_id, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $logStmt->execute([0, 'cron_bank_payment_escalated', 'invoice', $invoiceId, $logDetails, 'cron', 'cron']);
            } catch (Exception $e) {
                // Ignore logging errors
            }

        } else {
            echo "  -> No match found (pending " . $daysPending . " day(s), no escalation yet).\n";
        }
    }

    echo "\n=== Reconciliation Results ===\n";
    echo "Invoices matched and marked as paid: {$matched}\n";
    echo "Invoices escalated (>14 days pending): {$escalated}\n";
    echo "Completed at: " . date('Y-m-d H:i:s') . "\n";

    // Log cron execution completion
    try {
        $logDetails = "Completed: Matched={$matched}, Escalated={$escalated}";
        $stmt = $contentDb->prepare("INSERT INTO system_logs (user_id, action, entity_type, entity_id, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([0, 'cron_reconcile_bank_payments', 'cron', null, $logDetails, 'cron', 'cron']);
    } catch (Exception $e) {
        // Ignore logging errors
    }

} catch (Exception $e) {
    echo "\nFATAL ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";

    // Log error
    try {
        if (!isset($contentDb)) {
            $contentDb = Database::getContentDB();
        }
        $stmt = $contentDb->prepare("INSERT INTO system_logs (user_id, action, entity_type, entity_id, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([0, 'cron_reconcile_bank_payments', 'cron', null, "ERROR: " . $e->getMessage(), 'cron', 'cron']);
    } catch (Exception $logError) {
        // Ignore logging errors
    }

    exit(1);
}

echo "\n=== End of Bank Payment Reconciliation ===\n";

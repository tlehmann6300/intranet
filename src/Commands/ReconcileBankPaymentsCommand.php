<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\EasyVereinSync;
use DateTime;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * ReconcileBankPaymentsCommand
 *
 * Reconciles pending Vorkasse invoices against EasyVerein bank transactions.
 * Replaces cron/reconcile_bank_payments.php.
 *
 * Recommended schedule: daily at 06:00
 *   0 6 * * * php /path/to/bin/console app:reconcile-bank-payments
 */
#[AsCommand(
    name:        'app:reconcile-bank-payments',
    description: 'Reconciles pending bank-transfer invoices against EasyVerein transactions',
)]
class ReconcileBankPaymentsCommand extends Command
{
    public function __construct(
        private readonly EasyVereinSync  $syncService,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('=== Bank Payment Reconciliation ===');
        $output->writeln('Started at: ' . date('Y-m-d H:i:s'));

        try {
            $rechDb    = \Database::getRechDB();

            $output->writeln('Triggering bank sync...');
            $triggerResult = $this->syncService->triggerBankSync();
            if (! ($triggerResult['success'] ?? false)) {
                $output->writeln('<comment>Warning: Bank sync trigger failed: ' . ($triggerResult['error'] ?? 'unknown') . '</comment>');
            } else {
                $output->writeln('Bank sync triggered: ' . ($triggerResult['message'] ?? 'OK'));
            }

            $output->writeln('Waiting 5 seconds for transactions to load...');
            sleep(5);

            $output->writeln('Fetching bank transactions...');
            $transactions = EasyVereinSync::getBankTransactions(30);
            $output->writeln('Fetched ' . count($transactions) . ' transaction(s).');

            $stmt = $rechDb->prepare("
                SELECT id, description, amount, payment_purpose, easyverein_document_id, created_at
                FROM invoices
                WHERE status = 'pending' AND payment_method = 'bank_transfer'
                ORDER BY created_at ASC
            ");
            $stmt->execute();
            $pendingInvoices = $stmt->fetchAll();
            $output->writeln('Found ' . count($pendingInvoices) . ' pending bank-transfer invoice(s).');

            $matched   = 0;
            $escalated = 0;
            $errors    = 0;

            foreach ($pendingInvoices as $invoice) {
                try {
                    $invoiceId      = (int)$invoice['id'];
                    $paymentPurpose = (string)($invoice['payment_purpose'] ?? '');
                    $evDocId        = $invoice['easyverein_document_id'] ?? null;
                    $daysPending    = (new DateTime())->diff(new DateTime($invoice['created_at']))->days;

                    // Match against bank transactions
                    $paymentFound = false;
                    foreach ($transactions as $tx) {
                        $txPurpose   = (string)($tx['purpose']   ?? '');
                        $txReference = (string)($tx['reference'] ?? '');
                        $txInfo      = (string)($tx['info']      ?? '');
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
                        $rechDb->prepare("UPDATE invoices SET status = 'paid', paid_at = NOW(), paid_by_user_id = 0, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
                               ->execute([$invoiceId]);
                        if (! empty($evDocId)) {
                            $this->syncService->markInvoiceAsPaidInEV($evDocId);
                        }
                        $output->writeln("  Matched invoice #{$invoiceId}");
                        $matched++;
                    } elseif ($daysPending > 14) {
                        $output->writeln("  <comment>Escalating overdue invoice #{$invoiceId} ({$daysPending} days)</comment>");
                        $escalated++;
                    }
                } catch (\Exception $e) {
                    $errors++;
                    $this->logger->error('Reconcile error for invoice ' . ($invoice['id'] ?? '?') . ': ' . $e->getMessage());
                    $output->writeln('<error>  Error: ' . $e->getMessage() . '</error>');
                }
            }

            $output->writeln("\n=== Results ===");
            $output->writeln("Matched: {$matched}  Escalated: {$escalated}  Errors: {$errors}");
            $output->writeln('Finished at: ' . date('Y-m-d H:i:s'));

            return $errors === 0 ? Command::SUCCESS : Command::FAILURE;
        } catch (\Exception $e) {
            $this->logger->error('Reconcile bank payments failed: ' . $e->getMessage(), ['exception' => $e]);
            $output->writeln('<error>FATAL: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }
}

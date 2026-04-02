<?php

declare(strict_types=1);

namespace App\Jobs;

/**
 * MailJob
 *
 * Sends a single e-mail by delegating to the legacy MailService.
 *
 * Payload fields:
 *   - recipient  (string) – Recipient e-mail address
 *   - subject    (string) – E-mail subject
 *   - body       (string) – HTML body
 *   - sender     (string|null) – Optional override sender address
 *
 * Dispatch example:
 *
 *   $jobQueue->dispatch(MailJob::class, [
 *       'recipient' => 'someone@example.com',
 *       'subject'   => 'Hello',
 *       'body'      => '<p>World</p>',
 *   ]);
 */
class MailJob implements JobInterface
{
    public function handle(array $payload): void
    {
        $recipient = (string) ($payload['recipient'] ?? '');
        $subject   = (string) ($payload['subject']   ?? '');
        $body      = (string) ($payload['body']       ?? '');
        $sender    = isset($payload['sender']) ? (string) $payload['sender'] : null;

        if ($recipient === '' || $subject === '') {
            throw new \InvalidArgumentException('MailJob: recipient and subject are required');
        }

        \MailService::send($recipient, $subject, $body, $sender);
    }
}

<?php

declare(strict_types=1);

namespace App\Mail;

use RuntimeException;

final class LogMailProvider implements MailProvider
{
    public function __construct(private readonly string $path)
    {
    }

    public function send(MailMessage $message): MailResult
    {
        $directory = dirname($this->path);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            return new MailResult(false, null, 'Could not create mail log directory.');
        }

        $record = [
            'sent_at' => date(DATE_ATOM),
            'to' => $message->to,
            'subject' => $message->subject,
            'html_body' => $message->htmlBody,
            'attachments' => array_map(
                static fn(array $attachment): array => [
                    'name' => $attachment['name'] ?? basename((string) ($attachment['path'] ?? '')),
                    'path' => $attachment['path'] ?? null,
                ],
                $message->attachments
            ),
        ];

        $id = 'log-' . bin2hex(random_bytes(8));
        $record['message_id'] = $id;

        $written = file_put_contents(
            $this->path,
            json_encode($record, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );

        if ($written === false) {
            return new MailResult(false, null, 'Could not write mail log.');
        }

        return new MailResult(true, $id);
    }
}

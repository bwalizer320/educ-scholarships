<?php

declare(strict_types=1);

namespace App\Mail;

final class MailMessage
{
    public function __construct(
        public readonly string $to,
        public readonly string $subject,
        public readonly string $htmlBody,
        public readonly array $attachments = []
    ) {
    }
}

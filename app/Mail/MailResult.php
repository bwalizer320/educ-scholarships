<?php

declare(strict_types=1);

namespace App\Mail;

final class MailResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $providerMessageId = null,
        public readonly ?string $error = null
    ) {
    }
}

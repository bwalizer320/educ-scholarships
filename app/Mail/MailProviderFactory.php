<?php

declare(strict_types=1);

namespace App\Mail;

use App\Support\Env;
use RuntimeException;

final class MailProviderFactory
{
    public static function make(): MailProvider
    {
        $driver = strtolower(Env::get('MAIL_DRIVER', 'log') ?? 'log');

        return match ($driver) {
            'log' => new LogMailProvider(
                dirname(__DIR__, 2) . '/storage/logs/mail.log'
            ),
            'smtp' => new SmtpMailProvider(),
            default => throw new RuntimeException("Unsupported MAIL_DRIVER: {$driver}"),
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Http;

use RuntimeException;

final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['_csrf'];
    }

    public static function assertValid(?string $token): void
    {
        $expected = $_SESSION['_csrf'] ?? '';

        if (!is_string($token) || $expected === '' || !hash_equals($expected, $token)) {
            throw new RuntimeException('Your session token is invalid or expired.');
        }
    }
}

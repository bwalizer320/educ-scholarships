<?php

declare(strict_types=1);

namespace App\Support;

final class Flash
{
    public static function success(string $message): void
    {
        $_SESSION['_flash_success'] = $message;
    }

    public static function error(string $message): void
    {
        $_SESSION['_flash_error'] = $message;
    }

    public static function render(): string
    {
        $html = '';

        if (!empty($_SESSION['_flash_success'])) {
            $html .= '<div class="success-box" role="status">' . View::e($_SESSION['_flash_success']) . '</div>';
            unset($_SESSION['_flash_success']);
        }

        if (!empty($_SESSION['_flash_error'])) {
            $html .= '<div class="error" role="alert">' . View::e($_SESSION['_flash_error']) . '</div>';
            unset($_SESSION['_flash_error']);
        }

        return $html;
    }
}

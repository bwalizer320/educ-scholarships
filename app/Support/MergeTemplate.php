<?php

declare(strict_types=1);

namespace App\Support;

final class MergeTemplate
{
    public static function render(string $template, array $data, bool $escapeValues = true): string
    {
        return preg_replace_callback(
            '/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/',
            static function (array $matches) use ($data, $escapeValues): string {
                $key = $matches[1];
                $value = $data[$key] ?? '';

                if (is_bool($value)) {
                    $value = $value ? 'Yes' : 'No';
                }

                $text = (string) $value;

                return $escapeValues
                    ? htmlspecialchars($text, ENT_QUOTES, 'UTF-8')
                    : $text;
            },
            $template
        ) ?? $template;
    }
}

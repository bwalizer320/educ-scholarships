<?php

declare(strict_types=1);

namespace App\Services\ThankYou;

use HTMLPurifier;
use HTMLPurifier_Config;
use RuntimeException;

final class ThankYouService
{
    private HTMLPurifier $purifier;

    public function __construct()
    {
        $config = HTMLPurifier_Config::createDefault();
        $config->set('HTML.Allowed', 'p,br,strong,b,em,i,ul,ol,li');
        $config->set('AutoFormat.RemoveEmpty', true);
        $this->purifier = new HTMLPurifier($config);
    }

    public function sanitizeRichText(string $html): string
    {
        $clean = trim($this->purifier->purify($html));

        if ($clean === '') {
            throw new RuntimeException('Thank-you letter cannot be empty.');
        }

        return $clean;
    }

    public function isLate(\DateTimeInterface $submittedAt, \DateTimeInterface $deadline): bool
    {
        return $submittedAt > $deadline;
    }

    public function downloadBaseName(string $scholarshipName, string $studentName): string
    {
        $sanitize = static function (string $value): string {
            $value = preg_replace('/[^A-Za-z0-9]+/', ' ', $value) ?? '';
            $value = trim($value);
            $value = preg_replace('/\s+/', '', $value) ?? '';

            return $value !== '' ? $value : 'Scholarship';
        };

        return $sanitize($scholarshipName) . '_' . $sanitize($studentName);
    }
}

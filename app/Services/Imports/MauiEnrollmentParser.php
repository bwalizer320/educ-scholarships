<?php

declare(strict_types=1);

namespace App\Services\Imports;

use Generator;
use RuntimeException;

final class MauiEnrollmentParser
{
    public const EXPECTED_COLUMNS = [
        'SESSION_DESCR',
        'UNIVERSITY_ID',
        'STANDARD_FULL_NAME',
        'EMAIL',
        'PROGRAM_COLLEGE_ACAD_KEY',
        'STUD_CLASSIFICATION_DESCR',
        'PGMS_PROGRAM_DESCR',
        'PGMS_OBJECTIVE_KEY',
        'IS_PRIMARY',
        'ENROLLMENT_STATUS',
        'CITIZENSHIP_COUNTRY_DESCR',
        'CUM_UI_GRADED_GPA',
        'ENROLLED_CREDIT_HOURS',
        'HOME_COUNTRY',
        'HOME_COUNTY',
        'HOME_STATE_DESCR',
        'IS_PARENT_HIGHER_ED_GRAD',
        'PGMS_SUB_PROGRAM_DESCR',
        'POS_OVERALL_GRADED_GPA',
        'POS_UI_GRADED_GPA',
        'POS_UI_GRADED_HOURS',
        'RESIDENCY_COUNTY_DESCR',
        'RESIDENCY_STATE_DESCR',
        'TRUE_RESIDENCY_DESCR',
        'VETERAN_STATUS',
    ];

    public function rows(string $path): Generator
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Unable to open MAUI enrollment file.');
        }

        try {
            $header = fgetcsv($handle, 0, "\t");
            if ($header === false) {
                throw new RuntimeException('MAUI enrollment file is empty.');
            }

            $header = array_map(
                static fn(string $value): string => trim($value),
                $header
            );

            $missing = array_values(array_diff(self::EXPECTED_COLUMNS, $header));
            if ($missing !== []) {
                throw new RuntimeException(
                    'MAUI enrollment file is missing required columns: ' . implode(', ', $missing)
                );
            }

            $line = 1;
            while (($values = fgetcsv($handle, 0, "\t")) !== false) {
                $line++;

                if ($values === [null] || $values === []) {
                    continue;
                }

                $values = array_pad($values, count($header), null);
                $row = array_combine($header, array_slice($values, 0, count($header)));

                if ($row === false) {
                    throw new RuntimeException("Unable to parse MAUI row {$line}.");
                }

                $row['UNIVERSITY_ID'] = $this->normalizeUniversityId(
                    (string) ($row['UNIVERSITY_ID'] ?? '')
                );

                yield $line => $row;
            }
        } finally {
            fclose($handle);
        }
    }

    public function normalizeUniversityId(string $value): string
    {
        $value = trim($value);

        if (preg_match('/^="([^"]+)"$/', $value, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('/^"([^"]+)"$/', $value, $matches) === 1) {
            return $matches[1];
        }

        return $value;
    }

    public function toNullableFloat(mixed $value): ?float
    {
        $value = trim((string) $value);

        return $value === '' ? null : (float) $value;
    }

    public function toNullableBool(mixed $value): ?bool
    {
        $value = strtoupper(trim((string) $value));

        if ($value === '') {
            return null;
        }

        if (in_array($value, ['Y', 'YES', 'TRUE', '1'], true)) {
            return true;
        }

        if (in_array($value, ['N', 'NO', 'FALSE', '0'], true)) {
            return false;
        }

        return null;
    }
}

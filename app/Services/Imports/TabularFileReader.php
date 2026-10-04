<?php

declare(strict_types=1);

namespace App\Services\Imports;

use Generator;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

final class TabularFileReader
{
    public function headers(string $path, string $originalFilename): array
    {
        foreach ($this->rows($path, $originalFilename, 1) as $row) {
            return array_keys($row);
        }

        return [];
    }

    public function rows(string $path, string $originalFilename, ?int $limit = null): Generator
    {
        $extension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));

        if (in_array($extension, ['csv', 'tsv', 'txt'], true)) {
            yield from $this->delimitedRows($path, $extension === 'tsv' ? "\t" : null, $limit);
            return;
        }

        if (in_array($extension, ['xlsx', 'xls'], true)) {
            yield from $this->spreadsheetRows($path, $limit);
            return;
        }

        throw new RuntimeException('Applicant import must be CSV, TSV, XLS, or XLSX.');
    }

    private function delimitedRows(string $path, ?string $delimiter, ?int $limit): Generator
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Could not open applicant import file.');
        }

        try {
            $firstLine = fgets($handle);
            if ($firstLine === false) {
                throw new RuntimeException('Applicant import file is empty.');
            }

            if ($delimiter === null) {
                $delimiter = $this->detectDelimiter($firstLine);
            }

            rewind($handle);
            $header = fgetcsv($handle, 0, $delimiter);
            if ($header === false) {
                throw new RuntimeException('Could not read applicant import header.');
            }

            $header = $this->normalizeHeaders($header);
            $count = 0;

            while (($values = fgetcsv($handle, 0, $delimiter)) !== false) {
                if ($this->rowIsBlank($values)) {
                    continue;
                }

                $values = array_pad($values, count($header), null);
                $row = array_combine($header, array_slice($values, 0, count($header)));

                if ($row === false) {
                    continue;
                }

                yield $row;
                $count++;

                if ($limit !== null && $count >= $limit) {
                    break;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    private function spreadsheetRows(string $path, ?int $limit): Generator
    {
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, false);

        if ($rows === []) {
            throw new RuntimeException('Applicant spreadsheet is empty.');
        }

        $header = $this->normalizeHeaders(array_shift($rows));
        $count = 0;

        foreach ($rows as $values) {
            if ($this->rowIsBlank($values)) {
                continue;
            }

            $values = array_pad($values, count($header), null);
            $row = array_combine($header, array_slice($values, 0, count($header)));

            if ($row === false) {
                continue;
            }

            yield $row;
            $count++;

            if ($limit !== null && $count >= $limit) {
                break;
            }
        }

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
    }

    private function normalizeHeaders(array $headers): array
    {
        $seen = [];
        $result = [];

        foreach ($headers as $index => $header) {
            $name = trim((string) $header);
            if ($name === '') {
                $name = 'Column ' . ($index + 1);
            }

            $base = $name;
            $suffix = 2;

            while (isset($seen[strtolower($name)])) {
                $name = $base . ' (' . $suffix . ')';
                $suffix++;
            }

            $seen[strtolower($name)] = true;
            $result[] = $name;
        }

        return $result;
    }

    private function detectDelimiter(string $line): string
    {
        $counts = [
            "," => substr_count($line, ","),
            "\t" => substr_count($line, "\t"),
            ";" => substr_count($line, ";"),
        ];

        arsort($counts);
        $delimiter = array_key_first($counts);

        return ($counts[$delimiter] ?? 0) > 0 ? $delimiter : ",";
    }

    private function rowIsBlank(array $values): bool
    {
        foreach ($values as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }
}

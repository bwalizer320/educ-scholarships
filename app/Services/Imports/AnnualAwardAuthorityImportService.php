<?php

declare(strict_types=1);

namespace App\Services\Imports;

use App\Storage\LocalFileStorage;
use PDO;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;

final class AnnualAwardAuthorityImportService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly LocalFileStorage $storage
    ) {
    }

    public function import(int $cycleId, int $userId, string $tmpPath, string $filename): array
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($extension, ['xls', 'xlsx'], true)) {
            throw new RuntimeException('Annual award authority must be an XLS or XLSX workbook.');
        }

        $book = IOFactory::load($tmpPath);

        try {
            $simplified = $this->simplifiedSheets($book);
            if ($simplified === []) {
                throw new RuntimeException('The workbook does not contain a Schols - Simplified or Spring Awards - Simplified sheet.');
            }

            $allData = $this->allDataByFund($book);
            $stored = $this->storage->storeUploaded($tmpPath, $filename, 'annual-award-authority');

            $this->pdo->beginTransaction();
            try {
                $fileId = $this->insertFile($stored, $userId);
                $importId = $this->insertImport($cycleId, $fileId, $filename, $userId);
                $rows = 0;
                $created = 0;

                foreach ($simplified as [$sheet, $category]) {
                    foreach ($this->rows($sheet) as $row) {
                        $fundId = trim((string)($row['Fund ID'] ?? ''));
                        if ($fundId === '') {
                            continue;
                        }

                        $rows++;
                        $created += $this->importFund(
                            $cycleId,
                            $importId,
                            $filename,
                            $category,
                            $sheet->getTitle(),
                            $row,
                            $allData[$fundId] ?? null
                        ) ? 1 : 0;
                    }
                }

                $this->pdo->prepare(
                    'UPDATE annual_authority_imports
                     SET status = "completed", row_count = ?, imported_count = ?,
                         created_scholarship_count = ?, completed_at = NOW()
                     WHERE id = ?'
                )->execute([$rows, $rows, $created, $importId]);

                $this->pdo->commit();

                return [
                    'import_id' => $importId,
                    'row_count' => $rows,
                    'created_scholarships' => $created,
                ];
            } catch (\Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $e;
            }
        } finally {
            $book->disconnectWorksheets();
            unset($book);
        }
    }

    private function importFund(
        int $cycleId,
        int $importId,
        string $filename,
        string $category,
        string $simplifiedSheet,
        array $row,
        ?array $all
    ): bool {
        $fundId = trim((string)($row['Fund ID'] ?? ''));
        $name = trim((string)($row['Fund Name'] ?? ''));
        if ($fundId === '' || $name === '') {
            throw new RuntimeException('A fund row is missing Fund ID or Fund Name.');
        }

        $awardHeader = $this->findHeader(array_keys($row), '/^FY\d{2}\s+Award Total$/i');
        if ($awardHeader === null) {
            throw new RuntimeException("{$fundId}: FY## Award Total column was not found.");
        }

        $rawAuthority = $row[$awardHeader] ?? null;
        $authority = $this->money($rawAuthority);
        $authorityNote = $authority === null ? $this->text($rawAuthority) : null;
        $authority ??= 0.0;

        $awardPlan = $this->text($row['# & $ of Awards'] ?? null);
        [$plannedCount, $suggestedAmount] = $this->planHints($awardPlan);
        $department = $this->text($row['Dept/Area'] ?? $row['Dept'] ?? null);
        $level = $this->text($row['UG/GRAD'] ?? null);
        $summary = $this->text($row['Summarized Donor Intent'] ?? null);

        $scholarship = $this->findScholarship($fundId);
        $created = false;
        if (!$scholarship) {
            $scholarship = $this->createScholarship(
                $fundId,
                $name,
                $category,
                $summary,
                $this->text($all['Donor Intent Text'] ?? null),
                $awardPlan,
                $filename
            );
            $created = true;
        } else {
            $this->pdo->prepare('UPDATE scholarships SET name = ? WHERE id = ?')
                ->execute([$name, (int)$scholarship['id']]);
        }

        $intentId = (int)($scholarship['current_intent_version_id'] ?? 0);
        if ($intentId < 1) {
            throw new RuntimeException("{$fundId}: scholarship has no current donor-intent version.");
        }

        $existing = $this->cycleScholarship($cycleId, (int)$scholarship['id']);
        if ($existing) {
            $committed = $this->committed((int)$existing['id']);
            if ($authority + 0.00001 < $committed) {
                throw new RuntimeException(
                    "{$fundId}: imported authority $" . number_format($authority, 2)
                    . ' is below existing commitments of $' . number_format($committed, 2) . '.'
                );
            }

            $this->pdo->prepare(
                'UPDATE cycle_scholarships
                 SET total_authorized_amount = ?, award_category = ?, award_plan_text = ?,
                     source_department_area = ?, source_student_level = ?, authority_note = ?,
                     planned_new_award_count = COALESCE(?, planned_new_award_count),
                     suggested_new_award_amount = COALESCE(?, suggested_new_award_amount),
                     annual_authority_import_id = ?
                 WHERE id = ?'
            )->execute([
                $authority, $category, $awardPlan, $department, $level, $authorityNote,
                $plannedCount, $suggestedAmount, $importId, (int)$existing['id'],
            ]);
            $cycleScholarshipId = (int)$existing['id'];
        } else {
            $this->pdo->prepare(
                'INSERT INTO cycle_scholarships (
                    cycle_id, scholarship_id, intent_version_id, total_authorized_amount,
                    planned_new_award_count, suggested_new_award_amount, award_category,
                    award_plan_text, source_department_area, source_student_level,
                    authority_note, annual_authority_import_id, planning_status
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "draft")'
            )->execute([
                $cycleId, (int)$scholarship['id'], $intentId, $authority,
                $plannedCount, $suggestedAmount, $category, $awardPlan,
                $department, $level, $authorityNote, $importId,
            ]);
            $cycleScholarshipId = (int)$this->pdo->lastInsertId();
        }

        $this->insertSnapshot(
            $importId,
            $cycleScholarshipId,
            $awardHeader,
            $simplifiedSheet,
            $row,
            $all
        );

        return $created;
    }

    private function createScholarship(
        string $fundId,
        string $name,
        string $category,
        ?string $summary,
        ?string $fullIntent,
        ?string $awardPlan,
        string $filename
    ): array {
        $this->pdo->prepare(
            'INSERT INTO scholarships (public_id, uica_account_number, name, award_type, active)
             VALUES (UUID(), ?, ?, ?, 1)'
        )->execute([$fundId, $name, $category === 'spring_award' ? 'award' : 'scholarship']);
        $scholarshipId = (int)$this->pdo->lastInsertId();

        $intentText = $fullIntent ?? $summary ?? 'Donor intent was not included in the annual authority workbook.';
        $this->pdo->prepare(
            'INSERT INTO scholarship_intent_versions (
                scholarship_id, version_number, original_intent_text, structured_summary,
                source_reference, approved_at, active
             ) VALUES (?, 1, ?, ?, ?, NOW(), 1)'
        )->execute([$scholarshipId, $intentText, $summary, 'Annual authority workbook: ' . $filename]);
        $intentId = (int)$this->pdo->lastInsertId();

        $this->pdo->prepare('UPDATE scholarships SET current_intent_version_id = ? WHERE id = ?')
            ->execute([$intentId, $scholarshipId]);
        $this->pdo->prepare(
            'INSERT INTO scholarship_award_rules (
                intent_version_id, renewable, renewal_requires_current_criteria,
                amount_mode, single_semester_allowed_if_graduating, manual_amount_rule_text
             ) VALUES (?, 0, 1, "flexible_within_total", 1, ?)'
        )->execute([$intentId, $awardPlan]);

        return ['id' => $scholarshipId, 'current_intent_version_id' => $intentId];
    }

    private function insertSnapshot(
        int $importId,
        int $cycleScholarshipId,
        string $awardHeader,
        string $simplifiedSheet,
        array $simple,
        ?array $all
    ): void {
        $source = $all ?? $simple;
        $sourceSheet = (string)($source['__source_sheet'] ?? $simplifiedSheet);
        $sourceRow = (int)($source['__source_row'] ?? $simple['__source_row'] ?? 1);
        $payoutHeader = preg_replace('/Award Total$/i', 'Payout Projection', $awardHeader) ?: '';

        $this->pdo->prepare(
            'INSERT INTO cycle_fund_financial_snapshots (
                annual_authority_import_id, cycle_scholarship_id, source_sheet, source_row_number,
                endowed_invested, donor_report_required, fund_class, investment_pool,
                spendable_cash, spendable_investment, endowed_cash, endowed_investment,
                account_balance, next_fy_payout_projection, total_expenses, prior_year_total_expenses,
                ui_expenses, prior_year_ui_expenses, donor_report_recipient, donor_report_contact,
                uica_comments, summarized_donor_intent, source_values_json
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $importId, $cycleScholarshipId, $sourceSheet, max(1, $sourceRow),
            $this->yesNo($simple['Endowed/ Invested'] ?? null),
            $this->yesNo($simple['Donor Rpt'] ?? null),
            $this->text($source['Fund Class'] ?? null),
            $this->text($source['Investment Pool'] ?? null),
            $this->money($source['Spendable Cash'] ?? null),
            $this->money($source['Spendable Investment'] ?? null),
            $this->money($source['Endowed Cash'] ?? null),
            $this->money($source['Endowed Investment'] ?? null),
            $this->money($source['Balance'] ?? null),
            $payoutHeader !== '' ? $this->money($source[$payoutHeader] ?? null) : null,
            $this->money($source['Total Expenses'] ?? null),
            $this->money($source['Prior Year Total Expenses'] ?? null),
            $this->money($source['UI Expenses'] ?? null),
            $this->money($source['Prior Year UI Expenses'] ?? null),
            $this->text($source['Donor Report Recipient'] ?? null),
            $this->text($source['Donor Report Contact'] ?? null),
            $this->text($source['UICA Comments'] ?? null),
            $this->text($simple['Summarized Donor Intent'] ?? null),
            json_encode($source, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
        ]);
    }

    private function simplifiedSheets(Spreadsheet $book): array
    {
        $sheets = [];
        if ($sheet = $this->sheet($book, 'Schols - Simplified')) {
            $sheets[] = [$sheet, 'scholarship'];
        }
        if ($sheet = $this->sheet($book, 'Spring Awards - Simplified')) {
            $sheets[] = [$sheet, 'spring_award'];
        }
        return $sheets;
    }

    private function allDataByFund(Spreadsheet $book): array
    {
        $data = [];
        foreach (['Schols - All data', 'Spring Awards - All data'] as $needle) {
            $sheet = $this->sheet($book, $needle);
            if (!$sheet) {
                continue;
            }
            foreach ($this->rows($sheet) as $row) {
                $fundId = trim((string)($row['Fund ID'] ?? ''));
                if ($fundId !== '') {
                    $row['__source_sheet'] = $sheet->getTitle();
                    $data[$fundId] = $row;
                }
            }
        }
        return $data;
    }

    private function rows(Worksheet $sheet): array
    {
        $matrix = $sheet->toArray(null, true, true, false);
        $headerIndex = null;
        foreach (array_slice($matrix, 0, 6, true) as $index => $candidate) {
            $headers = array_map(fn($v) => $this->header((string)$v), $candidate);
            if (in_array('Fund ID', $headers, true)) {
                $headerIndex = (int)$index;
                break;
            }
        }
        if ($headerIndex === null) {
            throw new RuntimeException('Could not find Fund ID on sheet ' . $sheet->getTitle() . '.');
        }

        $headers = array_map(fn($v) => $this->header((string)$v), $matrix[$headerIndex]);
        $rows = [];
        foreach (array_slice($matrix, $headerIndex + 1, null, true) as $index => $values) {
            $row = ['__source_row' => (int)$index + 1];
            $hasData = false;
            foreach ($headers as $column => $header) {
                if ($header === '') {
                    continue;
                }
                $value = $values[$column] ?? null;
                $row[$header] = $value;
                $hasData = $hasData || ($value !== null && trim((string)$value) !== '');
            }
            if ($hasData) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    private function sheet(Spreadsheet $book, string $needle): ?Worksheet
    {
        foreach ($book->getWorksheetIterator() as $sheet) {
            if (str_contains(strtolower($sheet->getTitle()), strtolower($needle))) {
                return $sheet;
            }
        }
        return null;
    }

    private function findScholarship(string $fundId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, current_intent_version_id FROM scholarships WHERE uica_account_number = ? LIMIT 1'
        );
        $stmt->execute([$fundId]);
        return $stmt->fetch() ?: null;
    }

    private function cycleScholarship(int $cycleId, int $scholarshipId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM cycle_scholarships WHERE cycle_id = ? AND scholarship_id = ? LIMIT 1'
        );
        $stmt->execute([$cycleId, $scholarshipId]);
        return $stmt->fetch() ?: null;
    }

    private function committed(int $cycleScholarshipId): float
    {
        $stmt = $this->pdo->prepare(
            "SELECT
                COALESCE((SELECT SUM(proposed_current_amount) FROM renewal_candidates
                          WHERE cycle_scholarship_id = ? AND status = 'confirmed'), 0)
              + COALESCE((SELECT SUM(authorized_new_amount) FROM cycle_allocations
                          WHERE cycle_scholarship_id = ?), 0)"
        );
        $stmt->execute([$cycleScholarshipId, $cycleScholarshipId]);
        return (float)$stmt->fetchColumn();
    }

    private function insertFile(array $stored, int $userId): int
    {
        $this->pdo->prepare(
            'INSERT INTO file_objects (
                public_id, storage_driver, storage_key, original_filename,
                mime_type, size_bytes, sha256, uploaded_by_user_id
             ) VALUES (UUID(), ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $stored['storage_driver'], $stored['storage_key'], $stored['original_filename'],
            $stored['mime_type'], $stored['size_bytes'], $stored['sha256'], $userId,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    private function insertImport(int $cycleId, int $fileId, string $filename, int $userId): int
    {
        $this->pdo->prepare(
            'INSERT INTO annual_authority_imports (cycle_id, file_id, filename, status, imported_by_user_id)
             VALUES (?, ?, ?, "processing", ?)'
        )->execute([$cycleId, $fileId, $filename, $userId]);
        return (int)$this->pdo->lastInsertId();
    }

    private function planHints(?string $plan): array
    {
        if ($plan === null) {
            return [null, null];
        }
        if (preg_match('/(?:recommend\s+)?(\d+)\s+awards?\s+of\s+\$\s*([0-9,]+(?:\.\d{1,2})?)/i', $plan, $m)
            || preg_match('/(?:recommend\s+)?(\d+)\s+\$\s*([0-9,]+(?:\.\d{1,2})?)\s+awards?/i', $plan, $m)) {
            return [(int)$m[1], round((float)str_replace(',', '', $m[2]), 2)];
        }
        if (preg_match('/(?:recommend\s+)?(\d+)\s+awards?\b/i', $plan, $m)
            || preg_match('/\b(\d+)\s+award\b/i', $plan, $m)) {
            return [(int)$m[1], null];
        }
        return [null, null];
    }

    private function findHeader(array $headers, string $pattern): ?string
    {
        foreach ($headers as $header) {
            if (preg_match($pattern, trim((string)$header))) {
                return (string)$header;
            }
        }
        return null;
    }

    private function header(string $value): string
    {
        return trim((string)preg_replace('/\s+/', ' ', str_replace(["\r", "\n"], ' ', $value)));
    }

    private function text(mixed $value): ?string
    {
        $value = trim((string)$value);
        return $value === '' ? null : $value;
    }

    private function yesNo(mixed $value): ?int
    {
        return match (strtoupper(trim((string)$value))) {
            'Y', 'YES', 'TRUE', '1' => 1,
            'N', 'NO', 'FALSE', '0' => 0,
            default => null,
        };
    }

    private function money(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return round((float)$value, 2);
        }
        $value = preg_replace('/[^0-9.\-]/', '', trim((string)$value));
        return $value !== null && $value !== '' && $value !== '-' && is_numeric($value)
            ? round((float)$value, 2)
            : null;
    }
}

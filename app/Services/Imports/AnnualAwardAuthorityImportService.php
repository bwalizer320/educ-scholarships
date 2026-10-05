<?php

declare(strict_types=1);

namespace App\Services\Imports;

use App\Storage\LocalFileStorage;
use PDO;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
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

    public function import(int $cycleId, int $userId, string $sourcePath, string $filename): array
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($extension, ['xls', 'xlsx'], true)) {
            throw new RuntimeException('Annual award authority must be an XLS or XLSX workbook.');
        }
        if (!is_file($sourcePath)) {
            throw new RuntimeException('Annual award-authority workbook was not found.');
        }

        $book = IOFactory::load($sourcePath);

        try {
            $simplifiedSets = $this->simplifiedSheets($book);
            if ($simplifiedSets === []) {
                throw new RuntimeException('The workbook does not contain a scholarship or spring-award simplified sheet.');
            }

            $allDataRows = $this->allDataRows($book);
            $allDataByKey = [];
            foreach ($allDataRows as $row) {
                $allDataByKey[$this->rowKey($row)] = $row;
            }

            $masterSheet = $this->findSheet($book, 'UICA Account Bal and Activities');
            $masterRows = $masterSheet ? $this->rows($masterSheet) : [];
            $masterByFund = [];
            foreach ($masterRows as $row) {
                $fundId = $this->text($row['Fund ID'] ?? null);
                if ($fundId !== null) {
                    $masterByFund[$fundId] = $row;
                }
            }

            $stored = $this->storage->storeUploaded($sourcePath, $filename, 'annual-award-authority');

            $this->pdo->beginTransaction();
            try {
                $fileId = $this->insertFile($stored, $userId);
                $importId = $this->insertImport($cycleId, $fileId, $filename, $userId);

                $catalogCreated = 0;
                foreach ($masterRows as $row) {
                    $fundId = $this->text($row['Fund ID'] ?? null);
                    $name = $this->text($row['Fund Name'] ?? null);
                    if ($fundId === null || $name === null) {
                        continue;
                    }

                    [$scholarship, $created] = $this->ensureScholarship(
                        $fundId,
                        $fundId,
                        $name,
                        $this->awardTypeFromRow($row, 'scholarship'),
                        $this->text($row['Donor Intent Text'] ?? null),
                        null,
                        $filename
                    );
                    $catalogCreated += $created ? 1 : 0;

                    $this->storeSourceRow(
                        $importId,
                        (int)$scholarship['id'],
                        $masterSheet?->getTitle() ?? 'UICA Account Bal and Activities',
                        'uica_master',
                        $row
                    );
                }

                $authorityRows = 0;
                $authorityTotal = 0.0;
                $cycleCreated = 0;
                $variantCreated = 0;
                $warningCount = 0;

                foreach ($simplifiedSets as [$sheet, $category]) {
                    foreach ($this->rows($sheet) as $simple) {
                        $fundId = $this->text($simple['Fund ID'] ?? null);
                        $name = $this->text($simple['Fund Name'] ?? null);
                        if ($fundId === null || $name === null) {
                            continue;
                        }

                        $authorityRows++;
                        $sourceKey = $this->sourceRecordKey($fundId, $name, $masterByFund[$fundId] ?? null);
                        $allData = $allDataByKey[$this->rowKey($simple)] ?? null;
                        $master = $masterByFund[$fundId] ?? null;

                        $fullIntent = $this->text($allData['Donor Intent Text'] ?? null);
                        $summary = $this->text($simple['Summarized Donor Intent'] ?? null);
                        [$scholarship, $created] = $this->ensureScholarship(
                            $fundId,
                            $sourceKey,
                            $name,
                            $this->awardTypeFromRow($simple, $category),
                            $fullIntent ?? $summary,
                            $summary,
                            $filename
                        );
                        if ($created) {
                            $variantCreated++;
                        }

                        $this->storeSourceRow(
                            $importId,
                            (int)$scholarship['id'],
                            $sheet->getTitle(),
                            'simplified',
                            $simple
                        );
                        if ($allData !== null) {
                            $sourceSheet = (string)($allData['__source_sheet'] ?? 'All data');
                            $this->storeSourceRow(
                                $importId,
                                (int)$scholarship['id'],
                                $sourceSheet,
                                'all_data',
                                $allData
                            );
                        }

                        $awardHeader = $this->findHeader(array_keys($simple), '/^FY\d{2}\s+Award Total$/i');
                        if ($awardHeader === null) {
                            throw new RuntimeException("{$fundId}: FY## Award Total column was not found.");
                        }

                        $rawAuthority = $simple[$awardHeader] ?? null;
                        $authority = $this->money($rawAuthority);
                        $authorityNote = $authority === null ? $this->text($rawAuthority) : null;
                        if ($authority === null) {
                            $authority = 0.0;
                            $warningCount++;
                        }
                        $authorityTotal += $authority;

                        $awardPlan = $this->text($simple['# & $ of Awards'] ?? null);
                        [$plannedCount, $suggestedAmount] = $this->planHints($awardPlan);

                        $cycleScholarshipId = $this->upsertCycleScholarship(
                            $cycleId,
                            (int)$scholarship['id'],
                            (int)$scholarship['current_intent_version_id'],
                            $authority,
                            $category,
                            $awardPlan,
                            $this->text($simple['Dept/Area'] ?? $simple['Dept'] ?? null),
                            $this->text($simple['UG/GRAD'] ?? null),
                            $authorityNote,
                            $plannedCount,
                            $suggestedAmount,
                            $importId
                        );
                        if ((bool)($cycleScholarshipId['created'] ?? false)) {
                            $cycleCreated++;
                        }

                        $merged = array_merge($master ?? [], $allData ?? [], $simple);
                        $this->insertSnapshot(
                            $importId,
                            (int)$cycleScholarshipId['id'],
                            $awardHeader,
                            $sheet->getTitle(),
                            $merged,
                            [
                                'simplified' => $simple,
                                'all_data' => $allData,
                                'uica_master' => $master,
                            ]
                        );
                    }
                }

                $this->pdo->prepare(
                    'UPDATE annual_authority_imports
                     SET status = "completed",
                         row_count = ?,
                         imported_count = ?,
                         created_scholarship_count = ?,
                         updated_scholarship_count = ?,
                         warning_count = ?,
                         completed_at = NOW()
                     WHERE id = ?'
                )->execute([
                    $authorityRows,
                    $authorityRows,
                    $catalogCreated + $variantCreated,
                    max(0, $authorityRows - $cycleCreated),
                    $warningCount,
                    $importId,
                ]);

                $this->pdo->commit();

                return [
                    'import_id' => $importId,
                    'authority_rows' => $authorityRows,
                    'authority_total' => round($authorityTotal, 2),
                    'catalog_created' => $catalogCreated,
                    'variant_created' => $variantCreated,
                    'cycle_scholarships_created' => $cycleCreated,
                    'warnings' => $warningCount,
                    'source_rows' => count($masterRows) + count($allDataRows) + $authorityRows,
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

    /**
     * @return array{0:array<string,mixed>,1:bool}
     */
    private function ensureScholarship(
        string $fundId,
        string $sourceKey,
        string $name,
        string $awardType,
        ?string $fullIntent,
        ?string $summary,
        string $filename
    ): array {
        $stmt = $this->pdo->prepare(
            'SELECT id, current_intent_version_id, source_record_key
             FROM scholarships
             WHERE source_record_key = ?
                OR (source_record_key IS NULL AND uica_account_number = ? AND name = ?)
             ORDER BY source_record_key IS NULL ASC
             LIMIT 1'
        );
        $stmt->execute([$sourceKey, $fundId, $name]);
        $scholarship = $stmt->fetch();

        if ($scholarship) {
            $this->pdo->prepare(
                'UPDATE scholarships
                 SET uica_account_number = ?, source_record_key = ?, name = ?, award_type = ?, active = 1
                 WHERE id = ?'
            )->execute([$fundId, $sourceKey, $name, $awardType, (int)$scholarship['id']]);

            $intentId = (int)($scholarship['current_intent_version_id'] ?? 0);
            if ($intentId < 1) {
                $intentId = $this->createIntent((int)$scholarship['id'], $fullIntent, $summary, $filename);
            } else {
                $this->refreshImportedIntent($intentId, $fullIntent, $summary, $filename);
            }

            return [[
                'id' => (int)$scholarship['id'],
                'current_intent_version_id' => $intentId,
            ], false];
        }

        $this->pdo->prepare(
            'INSERT INTO scholarships (
                public_id, uica_account_number, source_record_key, name, award_type, active
             ) VALUES (UUID(), ?, ?, ?, ?, 1)'
        )->execute([$fundId, $sourceKey, $name, $awardType]);
        $scholarshipId = (int)$this->pdo->lastInsertId();

        $intentId = $this->createIntent($scholarshipId, $fullIntent, $summary, $filename);
        $this->pdo->prepare('UPDATE scholarships SET current_intent_version_id = ? WHERE id = ?')
            ->execute([$intentId, $scholarshipId]);

        return [[
            'id' => $scholarshipId,
            'current_intent_version_id' => $intentId,
        ], true];
    }

    private function createIntent(
        int $scholarshipId,
        ?string $fullIntent,
        ?string $summary,
        string $filename
    ): int {
        $intentText = $fullIntent ?? $summary ?? 'Donor intent was not included in the FY27 source workbook.';
        $this->pdo->prepare(
            'INSERT INTO scholarship_intent_versions (
                scholarship_id, version_number, original_intent_text, structured_summary,
                source_reference, approved_at, active
             ) VALUES (?, 1, ?, ?, ?, NOW(), 1)'
        )->execute([
            $scholarshipId,
            $intentText,
            $summary,
            'FY27 annual authority workbook: ' . $filename,
        ]);
        $intentId = (int)$this->pdo->lastInsertId();

        $this->pdo->prepare(
            'INSERT INTO scholarship_award_rules (
                intent_version_id, renewable, renewal_requires_current_criteria,
                amount_mode, single_semester_allowed_if_graduating
             ) VALUES (?, ?, 1, "flexible_within_total", 1)'
        )->execute([$intentId, $this->looksRenewable($intentText . ' ' . ($summary ?? '')) ? 1 : 0]);

        return $intentId;
    }

    private function refreshImportedIntent(
        int $intentId,
        ?string $fullIntent,
        ?string $summary,
        string $filename
    ): void {
        $stmt = $this->pdo->prepare(
            'SELECT source_reference FROM scholarship_intent_versions WHERE id = ?'
        );
        $stmt->execute([$intentId]);
        $source = (string)($stmt->fetchColumn() ?: '');

        if ($source !== '' && !str_starts_with($source, 'FY27 annual authority workbook:')) {
            return;
        }

        $intentText = $fullIntent ?? $summary;
        if ($intentText === null) {
            return;
        }

        $this->pdo->prepare(
            'UPDATE scholarship_intent_versions
             SET original_intent_text = ?, structured_summary = ?, source_reference = ?, approved_at = NOW()
             WHERE id = ?'
        )->execute([
            $intentText,
            $summary,
            'FY27 annual authority workbook: ' . $filename,
            $intentId,
        ]);

        $this->pdo->prepare(
            'UPDATE scholarship_award_rules SET renewable = ? WHERE intent_version_id = ?'
        )->execute([$this->looksRenewable($intentText . ' ' . ($summary ?? '')) ? 1 : 0, $intentId]);
    }

    /**
     * @return array{id:int,created:bool}
     */
    private function upsertCycleScholarship(
        int $cycleId,
        int $scholarshipId,
        int $intentId,
        float $authority,
        string $category,
        ?string $awardPlan,
        ?string $department,
        ?string $level,
        ?string $authorityNote,
        ?int $plannedCount,
        ?float $suggestedAmount,
        int $importId
    ): array {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM cycle_scholarships WHERE cycle_id = ? AND scholarship_id = ? LIMIT 1'
        );
        $stmt->execute([$cycleId, $scholarshipId]);
        $existing = $stmt->fetchColumn();

        if ($existing !== false) {
            $committed = $this->committed((int)$existing);
            if ($authority + 0.00001 < $committed) {
                throw new RuntimeException(
                    'Imported authority $' . number_format($authority, 2)
                    . ' is below existing commitments of $' . number_format($committed, 2) . '.'
                );
            }

            $this->pdo->prepare(
                'UPDATE cycle_scholarships
                 SET intent_version_id = ?,
                     total_authorized_amount = ?,
                     award_category = ?,
                     award_plan_text = ?,
                     source_department_area = ?,
                     source_student_level = ?,
                     authority_note = ?,
                     planned_new_award_count = COALESCE(?, planned_new_award_count),
                     suggested_new_award_amount = COALESCE(?, suggested_new_award_amount),
                     annual_authority_import_id = ?
                 WHERE id = ?'
            )->execute([
                $intentId, $authority, $category, $awardPlan, $department, $level,
                $authorityNote, $plannedCount, $suggestedAmount, $importId, (int)$existing,
            ]);

            return ['id' => (int)$existing, 'created' => false];
        }

        $this->pdo->prepare(
            'INSERT INTO cycle_scholarships (
                cycle_id, scholarship_id, intent_version_id, total_authorized_amount,
                planned_new_award_count, suggested_new_award_amount, award_category,
                award_plan_text, source_department_area, source_student_level,
                authority_note, annual_authority_import_id, planning_status
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "draft")'
        )->execute([
            $cycleId, $scholarshipId, $intentId, $authority, $plannedCount,
            $suggestedAmount, $category, $awardPlan, $department, $level,
            $authorityNote, $importId,
        ]);

        return ['id' => (int)$this->pdo->lastInsertId(), 'created' => true];
    }

    private function insertSnapshot(
        int $importId,
        int $cycleScholarshipId,
        string $awardHeader,
        string $sourceSheet,
        array $row,
        array $rawSources
    ): void {
        $priorPayoutHeader = preg_replace('/Award Total$/i', 'Payout Projection', $awardHeader);
        $nextPayout = $this->money($row['FY27 Payout Projection'] ?? $row['Next FY Payout Projection'] ?? null);
        $priorPayout = $this->money(
            ($priorPayoutHeader && isset($row[$priorPayoutHeader])) ? $row[$priorPayoutHeader] : ($row['FY26 Payout Projection'] ?? null)
        );

        $this->pdo->prepare(
            'INSERT INTO cycle_fund_financial_snapshots (
                annual_authority_import_id, cycle_scholarship_id, source_sheet, source_row_number,
                uica_top_giver, dormant, underutilized, endowment_code, college_code,
                department_code, area_code, endowed_invested, donor_report_required,
                memorial_honorary, geo, fund_status, fund_class, expense_code, ui_hospital_org,
                sport, fund_start_date, administrator_name, dni_value, investment_pool,
                spendable_cash, spendable_investment, endowed_cash, endowed_investment,
                pledge, illiquids, account_balance, gifts, pledge_payments, new_pledges,
                other_income, appreciation, interest_income, adjustments,
                total_expenses, prior_year_total_expenses, ui_expenses, prior_year_ui_expenses,
                last_ui_expense_date, prior_fy_payout_projection, next_fy_payout_projection,
                transfer_in, transfer_out, net_transfers, historical_gift_value, percent_above_hgv,
                donor_report_recipient, donor_report_contact, uica_comments,
                summarized_donor_intent, source_values_json
             ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $importId,
            $cycleScholarshipId,
            $sourceSheet,
            (int)($row['__source_row'] ?? 1),
            $this->text($row['UICA Top Giver'] ?? null),
            $this->text($row['Dormant'] ?? null),
            $this->text($row['Underutilized'] ?? null),
            $this->text($row['Endowment'] ?? null),
            $this->text($row['College'] ?? null),
            $this->text($row['Department'] ?? $row['Department '] ?? null),
            $this->text($row['Area'] ?? null),
            $this->yesNo($row['Endowed/ Invested'] ?? null),
            $this->yesNo($row['Donor Rpt'] ?? null),
            $this->text($row['Memorial / Honorary'] ?? $row['Memorial  / Honorary'] ?? null),
            $this->text($row['Geo'] ?? null),
            $this->text($row['Status'] ?? null),
            $this->text($row['Fund Class'] ?? null),
            $this->text($row['Expense'] ?? null),
            $this->text($row['UIHC Org'] ?? null),
            $this->text($row['Sport'] ?? null),
            $this->dateValue($row['Start Date'] ?? null),
            $this->text($row['Administrator'] ?? null),
            $this->text($row['DNI'] ?? null),
            $this->text($row['Investment Pool'] ?? null),
            $this->money($row['Spendable Cash'] ?? null),
            $this->money($row['Spendable Investment'] ?? null),
            $this->money($row['Endowed Cash'] ?? null),
            $this->money($row['Endowed Investment'] ?? null),
            $this->money($row['Pledge'] ?? null),
            $this->money($row['Illiquids'] ?? null),
            $this->money($row['Balance'] ?? null),
            $this->money($row['Gifts'] ?? null),
            $this->money($row['Pledge Payments'] ?? null),
            $this->money($row['New Pledges'] ?? null),
            $this->money($row['Other Income'] ?? null),
            $this->money($row['Appreciation'] ?? null),
            $this->money($row['Interest Income'] ?? null),
            $this->money($row['Adjustments'] ?? null),
            $this->money($row['Total Expenses'] ?? null),
            $this->money($row['Prior Year Total Expenses'] ?? null),
            $this->money($row['UI Expenses'] ?? null),
            $this->money($row['Prior Year UI Expenses'] ?? null),
            $this->dateValue($row['Last UI Expense Date'] ?? null),
            $priorPayout,
            $nextPayout,
            $this->money($row['Transfer In'] ?? null),
            $this->money($row['Transfer Out'] ?? null),
            $this->money($row['Net Transfers'] ?? null),
            $this->money($row['Historical Gift Value'] ?? null),
            $this->decimal($row['% Above HGV'] ?? null),
            $this->text($row['Donor Report Recipient'] ?? null),
            $this->text($row['Donor Report Contact'] ?? null),
            $this->text($row['UICA Comments'] ?? $row['Comments'] ?? null),
            $this->text($row['Summarized Donor Intent'] ?? null),
            json_encode($rawSources, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
        ]);
    }

    private function storeSourceRow(
        int $importId,
        int $scholarshipId,
        string $sheet,
        string $kind,
        array $row
    ): void {
        $this->pdo->prepare(
            'INSERT INTO annual_fund_source_rows (
                annual_authority_import_id, scholarship_id, source_sheet, source_kind,
                source_row_number, fund_id, fund_name, source_values_json
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $importId,
            $scholarshipId,
            $sheet,
            $kind,
            (int)($row['__source_row'] ?? 1),
            $this->text($row['Fund ID'] ?? null),
            $this->text($row['Fund Name'] ?? null),
            json_encode($row, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
        ]);
    }

    /**
     * @return array<int,array{0:Worksheet,1:string}>
     */
    private function simplifiedSheets(Spreadsheet $book): array
    {
        $sets = [];
        $scholarship = $this->findSheet($book, 'Schols - Simplified');
        if ($scholarship) {
            $sets[] = [$scholarship, 'scholarship'];
        }

        $spring = $this->findSheet($book, 'Spring Awards - Simplified');
        if ($spring) {
            $sets[] = [$spring, 'spring_award'];
        }

        return $sets;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function allDataRows(Spreadsheet $book): array
    {
        $rows = [];
        foreach (['Schols - All data', 'Spring Awards - All data'] as $needle) {
            $sheet = $this->findSheet($book, $needle);
            if (!$sheet) {
                continue;
            }
            foreach ($this->rows($sheet) as $row) {
                $row['__source_sheet'] = $sheet->getTitle();
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function rows(Worksheet $sheet): array
    {
        $matrix = $sheet->toArray(null, true, true, false);
        $headerIndex = null;

        foreach (array_slice($matrix, 0, 8, true) as $index => $candidate) {
            $headers = array_map(fn(mixed $v): string => $this->header($v), $candidate);
            if (in_array('Fund ID', $headers, true)) {
                $headerIndex = (int)$index;
                break;
            }
        }

        if ($headerIndex === null) {
            throw new RuntimeException('Could not find Fund ID on sheet ' . $sheet->getTitle() . '.');
        }

        $headers = array_map(fn(mixed $v): string => $this->header($v), $matrix[$headerIndex]);
        $rows = [];

        foreach (array_slice($matrix, $headerIndex + 1, null, true) as $index => $values) {
            $row = [
                '__source_sheet' => $sheet->getTitle(),
                '__source_row' => (int)$index + 1,
            ];
            $hasData = false;

            foreach ($headers as $column => $header) {
                if ($header === '') {
                    continue;
                }
                $value = $values[$column] ?? null;
                $row[$header] = $value;
                if ($value !== null && trim((string)$value) !== '') {
                    $hasData = true;
                }
            }

            if ($hasData) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function findSheet(Spreadsheet $book, string $needle): ?Worksheet
    {
        foreach ($book->getWorksheetIterator() as $sheet) {
            if (str_contains(strtolower($sheet->getTitle()), strtolower($needle))) {
                return $sheet;
            }
        }
        return null;
    }

    private function sourceRecordKey(string $fundId, string $name, ?array $master): string
    {
        $masterName = $this->text($master['Fund Name'] ?? null);
        if ($masterName === null || strcasecmp($masterName, $name) === 0) {
            return $fundId;
        }

        return $fundId . '|' . $name;
    }

    private function rowKey(array $row): string
    {
        return strtolower(trim((string)($row['Fund ID'] ?? '')))
            . '|'
            . strtolower(trim((string)($row['Fund Name'] ?? '')));
    }

    private function awardTypeFromRow(array $row, string $category): string
    {
        if ($category === 'spring_award') {
            return 'award';
        }

        $class = strtolower((string)($row['Fund Class'] ?? ''));
        return str_contains($class, 'fellow') ? 'fellowship' : 'scholarship';
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
            $stored['storage_driver'],
            $stored['storage_key'],
            $stored['original_filename'],
            $stored['mime_type'],
            $stored['size_bytes'],
            $stored['sha256'],
            $userId,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    private function insertImport(int $cycleId, int $fileId, string $filename, int $userId): int
    {
        $this->pdo->prepare(
            'INSERT INTO annual_authority_imports (
                cycle_id, file_id, filename, status, imported_by_user_id
             ) VALUES (?, ?, ?, "processing", ?)'
        )->execute([$cycleId, $fileId, $filename, $userId]);

        return (int)$this->pdo->lastInsertId();
    }

    /**
     * @return array{0:?int,1:?float}
     */
    private function planHints(?string $plan): array
    {
        if ($plan === null) {
            return [null, null];
        }

        if (preg_match('/(?:recommend\s+)?(\d+)\s+awards?\s+of\s+\$\s*([0-9,]+(?:\.\d{1,2})?)/i', $plan, $m)
            || preg_match('/(?:recommend\s+)?(\d+)\s+\$\s*([0-9,]+(?:\.\d{1,2})?)\s+awards?/i', $plan, $m)
            || preg_match('/(?:recommend\s+)?(\d+)\s+\$([0-9,]+(?:\.\d{1,2})?)\s+awards?/i', $plan, $m)) {
            return [(int)$m[1], round((float)str_replace(',', '', $m[2]), 2)];
        }

        if (preg_match('/(?:recommend\s+)?(\d+)\s+awards?\b/i', $plan, $m)
            || preg_match('/\b(\d+)\s+award\b/i', $plan, $m)) {
            return [(int)$m[1], null];
        }

        return [null, null];
    }

    private function looksRenewable(string $text): bool
    {
        $text = strtolower($text);
        if (str_contains($text, 'not renewable')) {
            return false;
        }
        return str_contains($text, 'renewable')
            || str_contains($text, 'may be renewed')
            || str_contains($text, 'can renew')
            || str_contains($text, 'renew for');
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

    private function header(mixed $value): string
    {
        return trim((string)preg_replace('/\s+/', ' ', str_replace(["\r", "\n"], ' ', (string)$value)));
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
        return $this->decimal($value, 2);
    }

    private function decimal(mixed $value, int $precision = 6): ?float
    {
        if (is_int($value) || is_float($value)) {
            return round((float)$value, $precision);
        }

        $value = preg_replace('/[^0-9.\-]/', '', trim((string)$value));
        return $value !== null && $value !== '' && $value !== '-' && is_numeric($value)
            ? round((float)$value, $precision)
            : null;
    }

    private function dateValue(mixed $value): ?string
    {
        if ($value === null || trim((string)$value) === '') {
            return null;
        }

        if (is_numeric($value)) {
            try {
                return ExcelDate::excelToDateTimeObject((float)$value)->format('Y-m-d');
            } catch (\Throwable) {
                return null;
            }
        }

        $timestamp = strtotime((string)$value);
        return $timestamp === false ? null : date('Y-m-d', $timestamp);
    }
}

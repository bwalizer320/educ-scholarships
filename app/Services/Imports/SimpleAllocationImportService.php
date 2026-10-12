<?php

declare(strict_types=1);

namespace App\Services\Imports;

use PDO;
use RuntimeException;

/**
 * Strict, repeatable current-cycle allocations import.
 *
 * The template contains no individual student data. Every row represents one
 * scholarship-to-unit allocation; repeated UICA/name combinations are allowed
 * for different units, but totals must agree. Imports are atomic.
 */
final class SimpleAllocationImportService
{
    public const HEADERS = [
        'academic_year', 'uica_account_number', 'scholarship_name', 'total_amount',
        'department', 'program', 'recruiting_pool', 'allocation_amount',
        'number_of_awards', 'suggested_award_amount',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function import(string $path, string $filename, array $cycle): array
    {
        if (!is_file($path) || filesize($path) > 5 * 1024 * 1024) {
            throw new RuntimeException('Upload a spreadsheet smaller than 5 MB.');
        }

        $reader = new TabularFileReader();
        $records = [];
        $totals = [];
        $seen = [];
        $line = 1;

        foreach ($reader->rows($path, $filename) as $raw) {
            $line++;
            if ($line > 5001) {
                throw new RuntimeException('Limit each upload to 5,000 allocation rows.');
            }

            $data = [];
            foreach ($raw as $key => $value) {
                $data[strtolower(trim((string) $key))] = trim((string) ($value ?? ''));
            }
            foreach (self::HEADERS as $column) {
                if (!array_key_exists($column, $data)) {
                    throw new RuntimeException('Missing required column: ' . $column);
                }
            }
            if ($data['academic_year'] !== (string) $cycle['label']) {
                throw new RuntimeException('Row ' . $line . ': academic_year must match the selected cycle, ' . $cycle['label'] . '.');
            }
            $account = $data['uica_account_number'];
            $name = $data['scholarship_name'];
            if ($account === '' || $name === '') {
                throw new RuntimeException('Row ' . $line . ': scholarship name and UICA account are required.');
            }

            $total = $this->money($data['total_amount'], 'total_amount', $line);
            $amount = $this->money($data['allocation_amount'], 'allocation_amount', $line);
            if ($total <= 0 || $amount <= 0) {
                throw new RuntimeException('Row ' . $line . ': amounts must be greater than zero.');
            }
            $unit = $this->resolveUnit($data['department'], $data['program'], $line);
            $count = $data['number_of_awards'] === '' ? null : filter_var(
                $data['number_of_awards'],
                FILTER_VALIDATE_INT,
                ['options'=>['min_range'=>1]]
            );
            if ($count === false) {
                throw new RuntimeException('Row ' . $line . ': number_of_awards must be a positive whole number.');
            }
            $suggested = $data['suggested_award_amount'] === ''
                ? null : $this->money($data['suggested_award_amount'], 'suggested_award_amount', $line);
            if ($suggested !== null && $suggested <= 0) {
                throw new RuntimeException('Row ' . $line . ': suggested_award_amount must be positive.');
            }

            $fundKey = strtolower($account . '|' . $name);
            $allocationKey = $fundKey . '|' . $unit . '|' . strtolower($data['recruiting_pool']);
            if (isset($seen[$allocationKey])) {
                throw new RuntimeException('Row ' . $line . ': duplicate scholarship/unit/pool allocation.');
            }
            $seen[$allocationKey] = true;
            if (isset($totals[$fundKey]) && abs($totals[$fundKey]['total'] - $total) > .005) {
                throw new RuntimeException('Row ' . $line . ': inconsistent authorized amount for the same scholarship.');
            }
            $totals[$fundKey] ??= ['total'=>$total, 'allocated'=>0.0];
            $totals[$fundKey]['allocated'] += $amount;
            if ($totals[$fundKey]['allocated'] > $total + .005) {
                throw new RuntimeException('Row ' . $line . ': allocations exceed the fund authority.');
            }

            $records[] = [
                'account'=>$account, 'name'=>$name, 'fund_key'=>$fundKey,
                'total'=>$total, 'amount'=>$amount, 'org_unit_id'=>$unit,
                'recruiting_pool'=>$data['recruiting_pool'] ?: null,
                'count'=>$count, 'suggested'=>$suggested,
            ];
        }

        if ($records === []) {
            throw new RuntimeException('The allocation file contains no records.');
        }

        $this->pdo->beginTransaction();
        try {
            $fundIds = [];
            $created = 0;
            $updated = 0;
            foreach ($records as $row) {
                if (!isset($fundIds[$row['fund_key']])) {
                    $fundIds[$row['fund_key']] = $this->getCycleFund($row, (int) $cycle['id']);
                }
                $cycleFundId = $fundIds[$row['fund_key']];
                $stmt = $this->pdo->prepare(
                    'SELECT * FROM cycle_allocations
                     WHERE cycle_scholarship_id = ? AND org_unit_id = ? FOR UPDATE'
                );
                $stmt->execute([$cycleFundId, $row['org_unit_id']]);
                $existing = $stmt->fetch();

                if ($existing) {
                    // Existing schema intentionally has one allocation per fund/unit.
                    if (($existing['recruiting_pool_name'] ?: null) !== $row['recruiting_pool']) {
                        throw new RuntimeException(
                            $row['name'] . ': an allocation already exists for this unit with a different recruiting pool.'
                        );
                    }
                    $committed = $this->committed((int) $existing['id']);
                    if ($row['amount'] + .005 < $committed) {
                        throw new RuntimeException($row['name'] . ': imported amount is below existing recommendations and awards.');
                    }
                    $this->pdo->prepare(
                        'UPDATE cycle_allocations SET authorized_new_amount = ?,
                         planned_new_award_count = ?, suggested_award_amount = ? WHERE id = ?'
                    )->execute([$row['amount'], $row['count'], $row['suggested'], $existing['id']]);
                    $updated++;
                } else {
                    $this->pdo->prepare(
                        "INSERT INTO cycle_allocations
                         (cycle_scholarship_id, org_unit_id, recruiting_pool_name,
                          authorized_new_amount, planned_new_award_count, suggested_award_amount, status)
                         VALUES (?, ?, ?, ?, ?, ?, 'confirmed')"
                    )->execute([
                        $cycleFundId, $row['org_unit_id'], $row['recruiting_pool'],
                        $row['amount'], $row['count'], $row['suggested'],
                    ]);
                    $created++;
                }
            }

            // All *existing* allocations (including ones absent from this file)
            // must remain within each scholarship's authorized total.
            $check = $this->pdo->prepare(
                'SELECT cs.total_authorized_amount, COALESCE(SUM(ca.authorized_new_amount),0) AS allocated
                 FROM cycle_scholarships cs
                 LEFT JOIN cycle_allocations ca ON ca.cycle_scholarship_id = cs.id
                 WHERE cs.id = ? GROUP BY cs.id'
            );
            foreach (array_unique(array_values($fundIds)) as $fundId) {
                $check->execute([$fundId]);
                $balance = $check->fetch();
                if ((float)$balance['allocated'] > (float)$balance['total_authorized_amount'] + .005) {
                    throw new RuntimeException('Import would overallocate a scholarship fund; no changes were saved.');
                }
            }
            $this->pdo->commit();
            return ['rows'=>count($records), 'created'=>$created, 'updated'=>$updated];
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function getCycleFund(array $row, int $cycleId): int
    {
        $find = $this->pdo->prepare(
            'SELECT id, current_intent_version_id FROM scholarships
             WHERE uica_account_number = ? AND name = ? ORDER BY id LIMIT 2'
        );
        $find->execute([$row['account'], $row['name']]);
        $matches = $find->fetchAll();
        if (count($matches) > 1) {
            throw new RuntimeException($row['name'] . ': multiple matching funds; reconcile the scholarship catalog first.');
        }

        if ($matches) {
            $scholarshipId = (int) $matches[0]['id'];
            $intentId = (int) $matches[0]['current_intent_version_id'];
        } else {
            $this->pdo->prepare(
                "INSERT INTO scholarships (public_id, uica_account_number, name, award_type, active)
                 VALUES (UUID(), ?, ?, 'scholarship', 1)"
            )->execute([$row['account'], $row['name']]);
            $scholarshipId = (int) $this->pdo->lastInsertId();
            $intentId = 0;
        }

        if ($intentId < 1) {
            $this->pdo->prepare(
                'INSERT INTO scholarship_intent_versions (scholarship_id, version_number, original_intent_text, structured_summary, active)
                 VALUES (?, 1, ?, ?, 1)'
            )->execute([$scholarshipId,
                'Not included in the standardized allocation file. Dean’s Office must verify the donor instrument.',
                'Eligibility criteria must be confirmed by the Dean’s Office.']);
            $intentId = (int) $this->pdo->lastInsertId();
            $this->pdo->prepare(
                'UPDATE scholarships SET current_intent_version_id = ? WHERE id = ?'
            )->execute([$intentId, $scholarshipId]);
            $this->pdo->prepare(
                "INSERT INTO scholarship_award_rules (intent_version_id, amount_mode)
                 VALUES (?, 'flexible_within_total')"
            )->execute([$intentId]);
        }

        $find = $this->pdo->prepare(
            'SELECT id, total_authorized_amount FROM cycle_scholarships
             WHERE cycle_id = ? AND scholarship_id = ? FOR UPDATE'
        );
        $find->execute([$cycleId, $scholarshipId]);
        $cycleFund = $find->fetch();
        if ($cycleFund) {
            $id = (int) $cycleFund['id'];
            if (abs((float)$cycleFund['total_authorized_amount'] - $row['total']) > .005) {
                $locked = $this->pdo->prepare(
                    'SELECT COUNT(*) FROM awards WHERE cycle_scholarship_id = ? AND status != ?'
                );
                $locked->execute([$id, 'cancelled']);
                if ((int) $locked->fetchColumn() > 0) {
                    throw new RuntimeException($row['name'] . ': authority cannot change after awards are created.');
                }
                $this->pdo->prepare(
                    'UPDATE cycle_scholarships SET total_authorized_amount = ? WHERE id = ?'
                )->execute([$row['total'], $id]);
            }
            return $id;
        }

        $this->pdo->prepare(
            "INSERT INTO cycle_scholarships
             (cycle_id, scholarship_id, intent_version_id, total_authorized_amount, planning_status)
             VALUES (?, ?, ?, ?, 'confirmed')"
        )->execute([$cycleId, $scholarshipId, $intentId, $row['total']]);
        return (int) $this->pdo->lastInsertId();
    }

    private function committed(int $allocationId): float
    {
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(SUM(recommended_amount), 0) FROM recommendations WHERE cycle_allocation_id = ?'
        );
        $stmt->execute([$allocationId]);
        $recs = (float) $stmt->fetchColumn();
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(total_amount),0) FROM awards
             WHERE cycle_allocation_id = ? AND recommendation_id IS NULL AND status != 'cancelled'"
        );
        $stmt->execute([$allocationId]);
        return $recs + (float)$stmt->fetchColumn();
    }

    private function resolveUnit(string $department, string $program, int $line): int
    {
        $name = $program !== '' ? $program : $department;
        if ($name === '') {
            throw new RuntimeException('Row ' . $line . ': enter a department or program.');
        }
        $type = $program !== '' ? 'program' : 'department';
        $stmt = $this->pdo->prepare(
            'SELECT u.id, p.name AS parent_name FROM org_units u
             LEFT JOIN org_units p ON p.id = u.parent_id
             WHERE u.name = ? AND u.unit_type = ? AND u.active = 1'
        );
        $stmt->execute([$name, $type]);
        $rows = $stmt->fetchAll();
        if ($department !== '' && $program !== '') {
            $rows = array_values(array_filter(
                $rows,
                static fn(array $unit): bool => $unit['parent_name'] === $department
            ));
        }
        if (count($rows) !== 1) {
            throw new RuntimeException('Row ' . $line . ': unit "' . $name . '" cannot be uniquely matched to an active College unit.');
        }
        return (int) $rows[0]['id'];
    }

    private function money(string $value, string $field, int $line): float
    {
        $value = trim(str_replace([',','$'], '', $value));
        if ($value === '' || !preg_match('/^\d{1,12}(?:\.\d{1,2})?$/', $value)) {
            throw new RuntimeException('Row ' . $line . ': invalid currency value in ' . $field . '.');
        }
        return round((float) $value, 2);
    }
}

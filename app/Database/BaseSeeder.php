<?php

declare(strict_types=1);

namespace App\Database;

use PDO;
use RuntimeException;

final class BaseSeeder
{
    private const DEPARTMENT_CODES = [
        'Counselor Education' => 'CE',
        'Educational Policy and Leadership Studies' => 'EPLS',
        'Psychological and Quantitative Foundations' => 'PSQF',
        'Teaching and Learning' => 'TL',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $programDataPath
    ) {
    }

    public function run(): array
    {
        if (!is_file($this->programDataPath)) {
            throw new RuntimeException('Program data seed file not found.');
        }

        $this->pdo->beginTransaction();

        try {
            $collegeId = $this->upsertOrgUnit(null, 'college', 'COE', 'College of Education', 0);

            $departmentIds = [];
            $sort = 10;

            foreach (self::DEPARTMENT_CODES as $name => $code) {
                $departmentIds[$name] = $this->upsertOrgUnit(
                    $collegeId,
                    'department',
                    $code,
                    $name,
                    $sort
                );
                $sort += 10;
            }

            $handle = fopen($this->programDataPath, 'rb');
            if ($handle === false) {
                throw new RuntimeException('Unable to open program data seed file.');
            }

            $header = fgetcsv($handle, 0, "\t");
            if ($header === false) {
                throw new RuntimeException('Program data seed file is empty.');
            }

            $programIds = [];
            $offeringCount = 0;

            while (($row = fgetcsv($handle, 0, "\t")) !== false) {
                if (count($row) < 4) {
                    continue;
                }

                [$sourceProgram, $objective, $subprogram, $subprogramId] = array_map('trim', $row);

                if (!isset($departmentIds[$sourceProgram])) {
                    continue;
                }

                $programKey = $sourceProgram . '|' . $subprogramId;
                if (!isset($programIds[$programKey])) {
                    $programIds[$programKey] = $this->upsertOrgUnit(
                        $departmentIds[$sourceProgram],
                        'program',
                        'PGMS-' . $subprogramId,
                        $subprogram,
                        100
                    );
                }

                $this->upsertOffering(
                    $programIds[$programKey],
                    $sourceProgram,
                    $objective,
                    $subprogram,
                    $subprogramId
                );

                $offeringCount++;
            }

            fclose($handle);
            $this->pdo->commit();

            return [
                'departments' => count($departmentIds),
                'programs' => count($programIds),
                'offerings' => $offeringCount,
            ];
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    private function upsertOrgUnit(
        ?int $parentId,
        string $unitType,
        string $code,
        string $name,
        int $sortOrder
    ): int {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM org_units WHERE code = ? AND ((parent_id = ?) OR (parent_id IS NULL AND ? IS NULL)) LIMIT 1'
        );
        $stmt->execute([$code, $parentId, $parentId]);
        $existing = $stmt->fetchColumn();

        if ($existing !== false) {
            $update = $this->pdo->prepare(
                'UPDATE org_units
                 SET unit_type = ?, name = ?, active = 1, sort_order = ?
                 WHERE id = ?'
            );
            $update->execute([$unitType, $name, $sortOrder, $existing]);

            return (int) $existing;
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO org_units
                (public_id, unit_type, parent_id, code, name, active, sort_order)
             VALUES (UUID(), ?, ?, ?, ?, 1, ?)'
        );
        $insert->execute([$unitType, $parentId, $code, $name, $sortOrder]);

        return (int) $this->pdo->lastInsertId();
    }

    private function upsertOffering(
        int $orgUnitId,
        string $sourceProgram,
        string $objective,
        string $subprogram,
        string $subprogramId
    ): void {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM program_offerings
             WHERE pgms_program_descr = ?
               AND pgms_objective_key = ?
               AND pgms_sub_program_descr = ?
               AND pgms_sub_program_id = ?
             LIMIT 1'
        );
        $stmt->execute([$sourceProgram, $objective, $subprogram, $subprogramId]);
        $existing = $stmt->fetchColumn();

        if ($existing !== false) {
            $update = $this->pdo->prepare(
                'UPDATE program_offerings
                 SET org_unit_id = ?, academic_level_code = ?, active = 1
                 WHERE id = ?'
            );
            $update->execute([$orgUnitId, 'G', $existing]);
            return;
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO program_offerings
                (org_unit_id, academic_level_code, pgms_program_descr, pgms_objective_key,
                 pgms_sub_program_descr, pgms_sub_program_id, active)
             VALUES (?, ?, ?, ?, ?, ?, 1)'
        );
        $insert->execute([
            $orgUnitId,
            'G',
            $sourceProgram,
            $objective,
            $subprogram,
            $subprogramId,
        ]);
    }
}

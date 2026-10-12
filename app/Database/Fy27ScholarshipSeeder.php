<?php

declare(strict_types=1);

namespace App\Database;

use App\Services\Imports\AnnualAwardAuthorityImportService;
use App\Storage\LocalFileStorage;
use App\Support\Env;
use PDO;
use RuntimeException;

final class Fy27ScholarshipSeeder
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly LocalFileStorage $storage
    ) {
    }

    public function run(string $workbookPath): array
    {
        if ((Env::get('APP_ENV', 'development') ?? 'development') === 'production') {
            throw new RuntimeException('FY27 test scholarship data cannot be loaded in production.');
        }

        if (!is_file($workbookPath)) {
            throw new RuntimeException("FY27 workbook not found: {$workbookPath}");
        }

        $cycleId = $this->ensureCycle();
        $userId = $this->seedActorUserId();

        $result = (new AnnualAwardAuthorityImportService($this->pdo, $this->storage))->import(
            $cycleId,
            $userId,
            $workbookPath,
            basename($workbookPath)
        );

        $cycleSummary = $this->cycleSummary($cycleId);

        return array_merge($result, [
            'cycle_id' => $cycleId,
            'cycle_label' => '2026-27',
            'catalog_scholarships' => $this->catalogCount(),
            'cycle_scholarships' => $cycleSummary['count'],
            'cycle_authority_total' => $cycleSummary['total'],
        ]);
    }

    private function ensureCycle(): int
    {
        $stmt = $this->pdo->query(
            "SELECT id FROM academic_cycles WHERE label = '2026-27' LIMIT 1"
        );
        $cycleId = $stmt->fetchColumn();

        if ($cycleId === false) {
            $this->pdo->prepare(
                "INSERT INTO academic_cycles (
                    public_id, label, start_year, end_year, status,
                    program_review_opens_at, program_review_due_at,
                    renewal_review_due_at, thank_you_due_at,
                    distribution_change_due_at, next_application_due_at, is_current
                 ) VALUES (
                    UUID(), '2026-27', 2026, 2027, 'setup',
                    '2027-02-01 08:00:00', '2027-03-15 17:00:00',
                    '2027-01-15 17:00:00', '2027-06-01 23:59:00',
                    '2027-05-15 23:59:00', '2027-02-28 23:59:00', 0
                 )"
            )->execute();
            $cycleId = (int)$this->pdo->lastInsertId();
        } else {
            $cycleId = (int)$cycleId;
        }

        $this->pdo->exec('UPDATE academic_cycles SET is_current = 0');
        $this->pdo->prepare(
            "UPDATE academic_cycles SET is_current = 1, status = 'setup' WHERE id = ?"
        )->execute([$cycleId]);

        $this->ensureTerm($cycleId, 'fall_2026', 'Fall 2026', 'fall', 2026);
        $this->ensureTerm($cycleId, 'spring_2027', 'Spring 2027', 'spring', 2027);
        $this->ensureChecklist($cycleId);

        return $cycleId;
    }

    private function ensureTerm(
        int $cycleId,
        string $termKey,
        string $displayName,
        string $season,
        int $year
    ): void {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM academic_terms WHERE cycle_id = ? AND term_key = ? LIMIT 1'
        );
        $stmt->execute([$cycleId, $termKey]);

        if ($stmt->fetchColumn() !== false) {
            return;
        }

        $this->pdo->prepare(
            'INSERT INTO academic_terms (
                cycle_id, term_key, display_name, season, calendar_year, is_primary_enrollment_term
             ) VALUES (?, ?, ?, ?, ?, 1)'
        )->execute([$cycleId, $termKey, $displayName, $season, $year]);
    }

    private function ensureChecklist(int $cycleId): void
    {
        $items = [
            'cycle_dates' => 'Cycle dates confirmed',
            'scholarship_catalog_reviewed' => 'Scholarship catalog reviewed',
            'donor_criteria_reviewed' => 'Donor criteria reviewed',
            'renewal_candidates_reviewed' => 'Renewal candidates reviewed',
            'annual_amounts_confirmed' => 'Annual scholarship amounts confirmed',
            'allocations_confirmed' => 'Program allocations confirmed',
            'rubrics_confirmed' => 'Program rubrics confirmed',
            'reviewers_confirmed' => 'Program reviewers confirmed',
            'letter_templates_confirmed' => 'Award letter templates confirmed',
            'email_template_confirmed' => 'Email templates confirmed',
            'thank_you_settings_confirmed' => 'Thank-you settings confirmed',
            'applicant_import_ready' => 'Applicant import ready',
        ];

        $check = $this->pdo->prepare(
            'SELECT id FROM cycle_checklist_items WHERE cycle_id = ? AND item_key = ? LIMIT 1'
        );
        $insert = $this->pdo->prepare(
            "INSERT INTO cycle_checklist_items (
                cycle_id, item_key, label, status, sort_order
             ) VALUES (?, ?, ?, 'not_started', ?)"
        );

        $sort = 10;
        foreach ($items as $key => $label) {
            $check->execute([$cycleId, $key]);
            if ($check->fetchColumn() === false) {
                $insert->execute([$cycleId, $key, $label, $sort]);
            }
            $sort += 10;
        }
    }

    private function seedActorUserId(): int
    {
        $id = $this->pdo->query(
            "SELECT id
             FROM users
             WHERE person_type = 'staff'
               AND active = 1
               AND staff_role IN ('system_admin','deans_office_admin')
             ORDER BY CASE staff_role WHEN 'system_admin' THEN 1 ELSE 2 END, id
             LIMIT 1"
        )->fetchColumn();

        if ($id !== false) {
            return (int)$id;
        }

        $stmt = $this->pdo->prepare(
            "INSERT INTO users (
                public_id, person_type, staff_role,
                first_name, last_name, display_name, email, active
             ) VALUES (
                UUID(), 'staff', 'deans_office_admin',
                'FY27', 'Seeder', 'FY27 Data Seeder', 'fy27.seed@example.test', 1
             )"
        );
        $stmt->execute();

        return (int)$this->pdo->lastInsertId();
    }

    /**
     * @return array{count:int,total:float}
     */
    private function cycleSummary(int $cycleId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) AS rows_count, COALESCE(SUM(total_authorized_amount),0) AS total
             FROM cycle_scholarships
             WHERE cycle_id = ?'
        );
        $stmt->execute([$cycleId]);
        $row = $stmt->fetch();

        return [
            'count' => (int)($row['rows_count'] ?? 0),
            'total' => round((float)($row['total'] ?? 0), 2),
        ];
    }

    private function catalogCount(): int
    {
        return (int)$this->pdo->query(
            'SELECT COUNT(*) FROM scholarships WHERE source_record_key IS NOT NULL'
        )->fetchColumn();
    }
}

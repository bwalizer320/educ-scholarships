<?php

declare(strict_types=1);

namespace App\Services\Cycle;

use PDO;
use RuntimeException;

final class CycleRolloverService
{
    private const CHECKLIST = [
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

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function createFromPrior(
        int $sourceCycleId,
        string $label,
        int $startYear,
        int $endYear
    ): int {
        if ($endYear !== $startYear + 1) {
            throw new RuntimeException('Academic cycle end year must follow the start year.');
        }

        $source = $this->pdo->prepare('SELECT * FROM academic_cycles WHERE id = ?');
        $source->execute([$sourceCycleId]);
        $prior = $source->fetch();

        if (!$prior) {
            throw new RuntimeException('Source academic cycle not found.');
        }

        $this->pdo->beginTransaction();

        try {
            $insert = $this->pdo->prepare(
                "INSERT INTO academic_cycles (
                    public_id, label, start_year, end_year, status, created_from_cycle_id, is_current
                 ) VALUES (UUID(), ?, ?, ?, 'setup', ?, 0)"
            );
            $insert->execute([$label, $startYear, $endYear, $sourceCycleId]);
            $newCycleId = (int) $this->pdo->lastInsertId();

            $this->createTerms($newCycleId, $startYear, $endYear);
            $this->createChecklist($newCycleId);
            $this->copyCycleScholarships($sourceCycleId, $newCycleId);
            $this->copyReviewUnits($sourceCycleId, $newCycleId, $prior['program_review_due_at']);
            $this->copyRubrics($sourceCycleId, $newCycleId);

            $this->pdo->commit();

            return $newCycleId;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function createTerms(int $cycleId, int $startYear, int $endYear): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO academic_terms
                (cycle_id, term_key, display_name, season, calendar_year, is_primary_enrollment_term)
             VALUES (?, ?, ?, ?, ?, 1)'
        );

        $stmt->execute([
            $cycleId,
            'fall_' . $startYear,
            'Fall ' . $startYear,
            'fall',
            $startYear,
        ]);

        $stmt->execute([
            $cycleId,
            'spring_' . $endYear,
            'Spring ' . $endYear,
            'spring',
            $endYear,
        ]);
    }

    private function createChecklist(int $cycleId): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO cycle_checklist_items
                (cycle_id, item_key, label, status, sort_order)
             VALUES (?, ?, ?, 'not_started', ?)"
        );

        $sort = 10;
        foreach (self::CHECKLIST as $key => $label) {
            $stmt->execute([$cycleId, $key, $label, $sort]);
            $sort += 10;
        }
    }

    private function copyCycleScholarships(int $sourceCycleId, int $newCycleId): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO cycle_scholarships (
                cycle_id, scholarship_id, intent_version_id, total_authorized_amount,
                planned_new_award_count, suggested_new_award_amount, planning_status
             )
             SELECT ?, scholarship_id, intent_version_id, total_authorized_amount,
                    planned_new_award_count, suggested_new_award_amount, 'draft'
             FROM cycle_scholarships
             WHERE cycle_id = ?"
        );
        $stmt->execute([$newCycleId, $sourceCycleId]);
    }

    private function copyReviewUnits(
        int $sourceCycleId,
        int $newCycleId,
        ?string $sourceDueAt
    ): void {
        $dueAt = $sourceDueAt;

        $stmt = $this->pdo->prepare(
            "INSERT INTO cycle_review_units (
                cycle_id, org_unit_id, primary_reviewer_user_id, review_method, due_at, status
             )
             SELECT ?, org_unit_id, primary_reviewer_user_id, review_method, COALESCE(?, due_at), 'not_started'
             FROM cycle_review_units
             WHERE cycle_id = ?"
        );
        $stmt->execute([$newCycleId, $dueAt, $sourceCycleId]);
    }

    private function copyRubrics(int $sourceCycleId, int $newCycleId): void
    {
        $sourceStmt = $this->pdo->prepare(
            'SELECT id, org_unit_id, name FROM rubrics WHERE cycle_id = ?'
        );
        $sourceStmt->execute([$sourceCycleId]);

        $insertRubric = $this->pdo->prepare(
            "INSERT INTO rubrics (cycle_id, org_unit_id, name, status, copied_from_rubric_id)
             VALUES (?, ?, ?, 'draft', ?)"
        );

        $sourceItems = $this->pdo->prepare(
            'SELECT label, description, max_points, sort_order
             FROM rubric_items
             WHERE rubric_id = ?
             ORDER BY sort_order, id'
        );

        $insertItem = $this->pdo->prepare(
            'INSERT INTO rubric_items (rubric_id, label, description, max_points, sort_order)
             VALUES (?, ?, ?, ?, ?)'
        );

        foreach ($sourceStmt->fetchAll() as $rubric) {
            $insertRubric->execute([
                $newCycleId,
                $rubric['org_unit_id'],
                $rubric['name'],
                $rubric['id'],
            ]);
            $newRubricId = (int) $this->pdo->lastInsertId();

            $sourceItems->execute([(int) $rubric['id']]);
            foreach ($sourceItems->fetchAll() as $item) {
                $insertItem->execute([
                    $newRubricId,
                    $item['label'],
                    $item['description'],
                    $item['max_points'],
                    $item['sort_order'],
                ]);
            }
        }
    }
}

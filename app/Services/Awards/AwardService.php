<?php

declare(strict_types=1);

namespace App\Services\Awards;

use PDO;
use RuntimeException;

final class AwardService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly DistributionService $distributionService
    ) {
    }

    public function createFromRecommendation(
        int $recommendationId,
        int $cycleId,
        int $studentId,
        int $cycleScholarshipId,
        int $allocationId,
        float $amount,
        string $eligibilityStatus,
        string $awardPeriod = 'academic_year'
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO awards (
                public_id, cycle_id, cycle_scholarship_id, cycle_allocation_id,
                student_id, award_origin, recommendation_id, total_amount,
                award_period, eligibility_status, enrollment_status, status
             ) VALUES (
                UUID(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
             )'
        );
        $stmt->execute([
            $cycleId,
            $cycleScholarshipId,
            $allocationId,
            $studentId,
            'new',
            $recommendationId,
            $amount,
            $awardPeriod,
            $eligibilityStatus,
            'not_checked',
            'pending_verification',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function createFromRenewal(
        int $renewalCandidateId,
        int $cycleId,
        int $studentId,
        int $cycleScholarshipId,
        float $amount,
        string $eligibilityStatus,
        string $awardPeriod = 'academic_year'
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO awards (
                public_id, cycle_id, cycle_scholarship_id, student_id,
                award_origin, renewal_candidate_id, total_amount,
                award_period, eligibility_status, enrollment_status, status
             ) VALUES (
                UUID(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
             )'
        );
        $stmt->execute([
            $cycleId,
            $cycleScholarshipId,
            $studentId,
            'renewal',
            $renewalCandidateId,
            $amount,
            $awardPeriod,
            $eligibilityStatus,
            'not_checked',
            'pending_verification',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function createDeanReallocation(
        int $cycleId,
        int $studentId,
        int $cycleScholarshipId,
        float $amount,
        string $eligibilityStatus,
        string $awardPeriod = 'academic_year'
    ): int {
        if ($amount <= 0) {
            throw new RuntimeException('Award amount must be greater than zero.');
        }

        $stmt = $this->pdo->prepare(
            "INSERT INTO awards (
                public_id, cycle_id, cycle_scholarship_id, cycle_allocation_id,
                student_id, award_origin, total_amount, award_period,
                eligibility_status, enrollment_status, status
             ) VALUES (
                UUID(), ?, ?, NULL, ?, 'deans_office_reallocation', ?, ?, ?, 'not_checked', 'pending_verification'
             )"
        );
        $stmt->execute([
            $cycleId,
            $cycleScholarshipId,
            $studentId,
            $amount,
            $awardPeriod,
            $eligibilityStatus,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function replaceDistributions(int $awardId, array $distributions): void
    {
        $awardStmt = $this->pdo->prepare('SELECT total_amount FROM awards WHERE id = ?');
        $awardStmt->execute([$awardId]);
        $total = $awardStmt->fetchColumn();

        if ($total === false) {
            throw new RuntimeException('Award not found.');
        }

        $this->distributionService->assertTotals((float) $total, $distributions);

        $this->pdo->beginTransaction();

        try {
            $delete = $this->pdo->prepare('DELETE FROM award_distributions WHERE award_id = ?');
            $delete->execute([$awardId]);

            $insert = $this->pdo->prepare(
                'INSERT INTO award_distributions (award_id, academic_term_id, amount, source)
                 VALUES (?, ?, ?, ?)'
            );

            foreach ($distributions as $distribution) {
                $insert->execute([
                    $awardId,
                    $distribution['academic_term_id'],
                    $distribution['amount'],
                    $distribution['source'] ?? 'deans_office',
                ]);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function markReadyToNotify(int $awardId): void
    {
        $stmt = $this->pdo->prepare(
            "SELECT a.*, COUNT(ad.id) AS distribution_count,
                    COALESCE(SUM(ad.amount), 0) AS distributed_total,
                    s.email
             FROM awards a
             JOIN students s ON s.id = a.student_id
             LEFT JOIN award_distributions ad ON ad.award_id = a.id
             WHERE a.id = ?
             GROUP BY a.id, s.email"
        );
        $stmt->execute([$awardId]);
        $award = $stmt->fetch();

        if (!$award) {
            throw new RuntimeException('Award not found.');
        }

        if ($award['enrollment_status'] !== 'verified_enrolled') {
            throw new RuntimeException('Enrollment must be verified before notification.');
        }

        if ((int) $award['distribution_count'] < 1) {
            throw new RuntimeException('Award distribution schedule is required.');
        }

        if (abs((float) $award['distributed_total'] - (float) $award['total_amount']) > 0.005) {
            throw new RuntimeException('Award distributions do not equal the award amount.');
        }

        if (trim((string) $award['email']) === '') {
            throw new RuntimeException('Student email is required.');
        }

        $update = $this->pdo->prepare(
            "UPDATE awards SET status = 'ready_to_notify' WHERE id = ?"
        );
        $update->execute([$awardId]);
    }
}

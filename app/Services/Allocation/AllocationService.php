<?php

declare(strict_types=1);

namespace App\Services\Allocation;

use PDO;
use RuntimeException;

final class AllocationService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function budgetSummary(int $cycleScholarshipId): array
    {
        $annualStmt = $this->pdo->prepare(
            'SELECT total_authorized_amount
             FROM cycle_scholarships
             WHERE id = ?'
        );
        $annualStmt->execute([$cycleScholarshipId]);
        $annual = $annualStmt->fetchColumn();

        if ($annual === false) {
            throw new RuntimeException('Cycle scholarship not found.');
        }

        $renewalStmt = $this->pdo->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN status = 'confirmed' THEN proposed_current_amount ELSE 0 END), 0) AS confirmed,
                COALESCE(SUM(CASE WHEN status IN ('pending_amount','pending_review','hold') THEN proposed_current_amount ELSE 0 END), 0) AS pending
             FROM renewal_candidates
             WHERE cycle_scholarship_id = ?"
        );
        $renewalStmt->execute([$cycleScholarshipId]);
        $renewals = $renewalStmt->fetch();

        $allocationStmt = $this->pdo->prepare(
            'SELECT COALESCE(SUM(authorized_new_amount), 0)
             FROM cycle_allocations
             WHERE cycle_scholarship_id = ?'
        );
        $allocationStmt->execute([$cycleScholarshipId]);
        $allocated = (float) $allocationStmt->fetchColumn();

        $total = (float) $annual;
        $confirmedRenewals = (float) ($renewals['confirmed'] ?? 0);
        $pendingRenewals = (float) ($renewals['pending'] ?? 0);
        $availableNew = max(0, $total - $confirmedRenewals);

        return [
            'annual_total' => $total,
            'confirmed_renewals' => $confirmedRenewals,
            'pending_renewals' => $pendingRenewals,
            'available_new' => $availableNew,
            'allocated_new' => $allocated,
            'unallocated_new' => max(0, $availableNew - $allocated),
        ];
    }

    public function assertAllocationFits(int $cycleScholarshipId, float $proposedTotal): void
    {
        $summary = $this->budgetSummary($cycleScholarshipId);

        if ($proposedTotal > $summary['available_new'] + 0.00001) {
            throw new RuntimeException('Program allocations exceed the scholarship amount available for new awards.');
        }
    }
}

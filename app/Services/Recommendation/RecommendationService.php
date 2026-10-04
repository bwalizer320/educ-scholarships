<?php

declare(strict_types=1);

namespace App\Services\Recommendation;

use PDO;
use RuntimeException;

final class RecommendationService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function validateAllocationRecommendations(int $allocationId): array
    {
        $allocationStmt = $this->pdo->prepare(
            'SELECT ca.authorized_new_amount, ca.no_candidate, sar.amount_mode
             FROM cycle_allocations ca
             JOIN cycle_scholarships cs ON cs.id = ca.cycle_scholarship_id
             JOIN scholarship_award_rules sar ON sar.intent_version_id = cs.intent_version_id
             WHERE ca.id = ?'
        );
        $allocationStmt->execute([$allocationId]);
        $allocation = $allocationStmt->fetch();

        if (!$allocation) {
            throw new RuntimeException('Allocation not found.');
        }

        $recsStmt = $this->pdo->prepare(
            'SELECT id, rank_position, recommended_amount, eligibility_status_at_submission
             FROM recommendations
             WHERE cycle_allocation_id = ?
             ORDER BY rank_position'
        );
        $recsStmt->execute([$allocationId]);
        $recommendations = $recsStmt->fetchAll();

        if ($recommendations === [] && !(bool) $allocation['no_candidate']) {
            throw new RuntimeException('At least one recommendation is required unless no candidate is explicitly selected.');
        }

        $sum = array_sum(array_map(
            static fn(array $row): float => (float) $row['recommended_amount'],
            $recommendations
        ));

        if ($sum > (float) $allocation['authorized_new_amount'] + 0.00001) {
            throw new RuntimeException('Recommended awards exceed the allocation amount.');
        }

        if ($allocation['amount_mode'] === 'equal_among_recipients' && count($recommendations) > 1) {
            $amounts = array_unique(array_map(
                static fn(array $row): string => number_format((float) $row['recommended_amount'], 2, '.', ''),
                $recommendations
            ));

            if (count($amounts) !== 1) {
                throw new RuntimeException('This scholarship requires equal award amounts among recipients.');
            }
        }

        return [
            'recommendations' => $recommendations,
            'recommended_total' => $sum,
            'authorized_total' => (float) $allocation['authorized_new_amount'],
            'has_eligibility_warnings' => (bool) array_filter(
                $recommendations,
                static fn(array $row): bool => in_array(
                    $row['eligibility_status_at_submission'],
                    ['potentially_ineligible', 'insufficient_information'],
                    true
                )
            ),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Awards;

use RuntimeException;

final class DistributionService
{
    public function defaultDistributions(
        float $totalAmount,
        string $awardPeriod,
        int $fallTermId,
        int $springTermId,
        bool $studentTeachingRequired = false,
        ?int $studentTeachingTermId = null,
        float $splitThreshold = 500.00
    ): array {
        if ($totalAmount <= 0) {
            throw new RuntimeException('Award amount must be greater than zero.');
        }

        if ($studentTeachingRequired) {
            if ($studentTeachingTermId === null) {
                return [];
            }

            return [[
                'academic_term_id' => $studentTeachingTermId,
                'amount' => round($totalAmount, 2),
                'source' => 'default',
            ]];
        }

        if ($awardPeriod === 'fall') {
            return [[
                'academic_term_id' => $fallTermId,
                'amount' => round($totalAmount, 2),
                'source' => 'default',
            ]];
        }

        if ($awardPeriod === 'spring') {
            return [[
                'academic_term_id' => $springTermId,
                'amount' => round($totalAmount, 2),
                'source' => 'default',
            ]];
        }

        if ($totalAmount <= $splitThreshold) {
            return [[
                'academic_term_id' => $fallTermId,
                'amount' => round($totalAmount, 2),
                'source' => 'default',
            ]];
        }

        $fallAmount = round($totalAmount / 2, 2);
        $springAmount = round($totalAmount - $fallAmount, 2);

        return [
            [
                'academic_term_id' => $fallTermId,
                'amount' => $fallAmount,
                'source' => 'default',
            ],
            [
                'academic_term_id' => $springTermId,
                'amount' => $springAmount,
                'source' => 'default',
            ],
        ];
    }

    public function assertTotals(float $awardTotal, array $distributions): void
    {
        $sum = array_sum(array_map(
            static fn(array $row): float => (float) ($row['amount'] ?? 0),
            $distributions
        ));

        if (abs($sum - $awardTotal) > 0.005) {
            throw new RuntimeException('Award distributions must equal the total award amount.');
        }
    }
}

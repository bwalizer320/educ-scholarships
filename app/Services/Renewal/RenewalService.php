<?php

declare(strict_types=1);

namespace App\Services\Renewal;

use PDO;
use RuntimeException;

final class RenewalService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function confirm(int $renewalId, float $amount, int $userId): void
    {
        if ($amount <= 0) {
            throw new RuntimeException('Renewal amount must be greater than zero.');
        }

        $stmt = $this->pdo->prepare(
            'UPDATE renewal_candidates
             SET proposed_current_amount = ?,
                 status = ?,
                 reviewed_by_user_id = ?,
                 reviewed_at = NOW()
             WHERE id = ?'
        );
        $stmt->execute([$amount, 'confirmed', $userId, $renewalId]);

        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Renewal candidate not found.');
        }
    }

    public function hold(int $renewalId, ?float $amount, int $userId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE renewal_candidates
             SET proposed_current_amount = ?,
                 status = ?,
                 reviewed_by_user_id = ?,
                 reviewed_at = NOW()
             WHERE id = ?'
        );
        $stmt->execute([$amount, 'hold', $userId, $renewalId]);
    }

    public function decline(int $renewalId, int $userId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE renewal_candidates
             SET proposed_current_amount = NULL,
                 status = ?,
                 reviewed_by_user_id = ?,
                 reviewed_at = NOW()
             WHERE id = ?'
        );
        $stmt->execute(['declined', $userId, $renewalId]);
    }
}

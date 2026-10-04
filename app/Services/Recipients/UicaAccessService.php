<?php

declare(strict_types=1);

namespace App\Services\Recipients;

use PDO;

final class UicaAccessService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function canReview(int $userId, ?string $staffRole, int $scholarshipId, int $cycleId): bool
    {
        if (in_array($staffRole, ['system_admin','deans_office_admin'], true)) {
            return true;
        }

        $stmt = $this->pdo->prepare(
            "SELECT 1
             FROM user_uica_access
             WHERE user_id = ?
               AND scholarship_id = ?
               AND active = 1
               AND (cycle_id IS NULL OR cycle_id = ?)
             LIMIT 1"
        );
        $stmt->execute([$userId, $scholarshipId, $cycleId]);

        return (bool) $stmt->fetchColumn();
    }

    public function accessibleScholarshipIds(int $userId, ?string $staffRole, int $cycleId): ?array
    {
        if (in_array($staffRole, ['system_admin','deans_office_admin'], true)) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            "SELECT DISTINCT scholarship_id
             FROM user_uica_access
             WHERE user_id = ?
               AND active = 1
               AND (cycle_id IS NULL OR cycle_id = ?)"
        );
        $stmt->execute([$userId, $cycleId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}

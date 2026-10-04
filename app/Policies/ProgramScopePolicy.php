<?php

declare(strict_types=1);

namespace App\Policies;

use App\Auth\Gate;
use PDO;

final class ProgramScopePolicy
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function canViewOrgUnit(int $userId, ?string $staffRole, int $orgUnitId, ?int $cycleId = null): bool
    {
        if (Gate::isAdmin($staffRole)) {
            return true;
        }

        if ($staffRole === 'program_coordinator') {
            return $this->hasAssignment($userId, $orgUnitId, ['coordinator', 'reviewer'], $cycleId);
        }

        if ($staffRole === 'department_chair') {
            if ($this->hasAssignment($userId, $orgUnitId, ['chair'], $cycleId)) {
                return true;
            }

            $stmt = $this->pdo->prepare(
                'SELECT parent_id FROM org_units WHERE id = ?'
            );
            $stmt->execute([$orgUnitId]);
            $parentId = $stmt->fetchColumn();

            return $parentId !== false
                && $this->hasAssignment($userId, (int) $parentId, ['chair'], $cycleId);
        }

        return false;
    }

    private function hasAssignment(
        int $userId,
        int $orgUnitId,
        array $types,
        ?int $cycleId
    ): bool {
        $placeholders = implode(',', array_fill(0, count($types), '?'));

        $sql = "SELECT 1
                FROM user_unit_assignments
                WHERE user_id = ?
                  AND org_unit_id = ?
                  AND assignment_type IN ({$placeholders})
                  AND active = 1
                  AND (cycle_id IS NULL OR cycle_id = ?)
                LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$userId, $orgUnitId, ...$types, $cycleId]);

        return (bool) $stmt->fetchColumn();
    }
}

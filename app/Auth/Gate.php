<?php

declare(strict_types=1);

namespace App\Auth;

final class Gate
{
    public static function isAdmin(?string $role): bool
    {
        return in_array($role, [
            Role::SystemAdmin->value,
            Role::DeansOfficeAdmin->value,
        ], true);
    }

    public static function canManageSystem(?string $role): bool
    {
        return $role === Role::SystemAdmin->value;
    }

    public static function canManageScholarships(?string $role): bool
    {
        return self::isAdmin($role);
    }

    public static function canReviewProgram(?string $role): bool
    {
        return in_array($role, [
            Role::SystemAdmin->value,
            Role::DeansOfficeAdmin->value,
            Role::ProgramCoordinator->value,
        ], true);
    }

    public static function canViewDonorIntent(string $personType): bool
    {
        return $personType === 'staff';
    }
}

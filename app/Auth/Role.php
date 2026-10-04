<?php

declare(strict_types=1);

namespace App\Auth;

enum Role: string
{
    case SystemAdmin = 'system_admin';
    case DeansOfficeAdmin = 'deans_office_admin';
    case DepartmentChair = 'department_chair';
    case ProgramCoordinator = 'program_coordinator';
}

<?php

declare(strict_types=1);

use App\Auth\AuthService;
use App\Controllers\Admin\CycleWizardController;
use App\Database\Connection;

require dirname(__DIR__) . '/app/bootstrap.php';

$pdo = Connection::get();
$cycle = $pdo->query('SELECT id FROM academic_cycles WHERE is_current = 1 LIMIT 1')->fetch();

if (!$cycle) {
    throw new RuntimeException('Cycle wizard smoke test requires a current seeded academic cycle.');
}

$staffId = $pdo->query("SELECT id FROM users WHERE person_type = 'staff' ORDER BY id LIMIT 1")->fetchColumn();
if (!$staffId) {
    throw new RuntimeException('Cycle wizard smoke test requires a seeded staff user.');
}

// Test the rendered controller content, not just PHP syntax. Session role
// stands in for an authenticated Dean's Office administrator.
$_SESSION['auth_user_id'] = (int)$staffId;
$_SESSION['auth_person_type'] = 'staff';
$_SESSION['auth_staff_role'] = 'system_admin';
$_SESSION['auth_display_name'] = 'Cycle Wizard Test';

$wizard = new CycleWizardController($pdo, new AuthService($pdo));

try {
    for ($step = 1; $step <= 6; $step++) {
        $_GET['step'] = (string)$step;
        $_SERVER['REQUEST_URI'] = '/admin/cycles/' . (int)$cycle['id'] . '/setup?step=' . $step;

        $html = $wizard->index(['id' => (string)$cycle['id']]);

        if (!str_contains($html, 'Step ' . $step . ' of 6')) {
            throw new RuntimeException('Cycle wizard step ' . $step . ' did not render its progress heading.');
        }
        if (!str_contains($html, 'step-number">0' . $step . '</span>')) {
            throw new RuntimeException('Cycle wizard step numbers did not render correctly on step ' . $step . '.');
        }
        if (!str_contains($html, 'Continue') && $step < 6) {
            throw new RuntimeException('Cycle wizard step ' . $step . ' did not provide a Continue link.');
        }
    }
} finally {
    unset($_GET['step']);
    unset($_SESSION['auth_user_id'], $_SESSION['auth_person_type'], $_SESSION['auth_staff_role'], $_SESSION['auth_display_name']);
}

fwrite(STDOUT, "All six scholarship cycle wizard steps rendered successfully.\n");

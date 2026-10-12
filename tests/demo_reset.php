<?php

declare(strict_types=1);

use App\Database\Connection;
use App\Database\TestDataCleaner;

require dirname(__DIR__) . '/app/bootstrap.php';

$pdo = Connection::get();
$cleaner = new TestDataCleaner($pdo);
$beforeOrg = (int)$pdo->query('SELECT COUNT(*) FROM org_units')->fetchColumn();
$beforeUsers = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$beforeOfferings = (int)$pdo->query('SELECT COUNT(*) FROM program_offerings')->fetchColumn();
$preview = $cleaner->preview('2027-28');
if ($preview['counts']['awards'] < 1 || $preview['counts']['applications'] < 1
    || $preview['counts']['scholarship_funds'] < 1) {
    throw new RuntimeException('Expected seeded test scholarship data before cleanup.');
}

// Apply and assert the same academic cycle can be reused for a new award file.
$cleaner->clean('2027-28');
$after = $cleaner->preview('2027-28');
foreach (['awards','applications','scholarship_funds','allocations','recommendations','enrollment_imports','applicant_imports'] as $table) {
    if ($after['counts'][$table] !== 0) {
        throw new RuntimeException('Expected 0 for cleared ' . $table . ', got ' . $after['counts'][$table]);
    }
}
if ((int)$pdo->query('SELECT COUNT(*) FROM org_units')->fetchColumn() !== $beforeOrg
    || (int)$pdo->query('SELECT COUNT(*) FROM program_offerings')->fetchColumn() !== $beforeOfferings
    || (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() !== $beforeUsers) {
    throw new RuntimeException('Reset changed department, program, offering, or staff-user configuration.');
}
$cycle = $pdo->query("SELECT id, status, is_current FROM academic_cycles WHERE label = '2027-28'")->fetch();
if (!$cycle || $cycle['status'] !== 'setup' || (int)$cycle['is_current'] !== 1) {
    throw new RuntimeException('Current academic cycle should be reset to setup, not deleted.');
}
$termStmt = $pdo->prepare('SELECT COUNT(*) FROM academic_terms WHERE cycle_id = ?');
$termStmt->execute([(int)$cycle['id']]);
if ((int)$termStmt->fetchColumn() !== 2) {
    throw new RuntimeException('Fall/Spring academic term configuration must remain intact.');
}
fwrite(STDOUT, "Guarded test-data cleanup preserved College configuration and current cycle.\n");

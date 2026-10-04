<?php

declare(strict_types=1);

use App\Database\Connection;
use App\Services\Recipients\UicaAccessService;

require dirname(__DIR__) . '/app/bootstrap.php';

$pdo = Connection::get();
$failures = [];

$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$cycle = $pdo->query(
    "SELECT * FROM academic_cycles WHERE label = '2027-28' LIMIT 1"
)->fetch();

$assert((bool) $cycle, 'Fake academic cycle should exist.');
$assert((int) ($cycle['is_current'] ?? 0) === 1, 'Fake academic cycle should be current.');

$scholarships = (int) $pdo->query(
    "SELECT COUNT(*) FROM scholarships WHERE uica_account_number LIKE 'TEST-UICA-%'"
)->fetchColumn();
$assert($scholarships >= 2, 'Fake scholarships should be loaded.');

$reviewer = $pdo->query(
    "SELECT * FROM users WHERE email = 'jordan.reviewer@example.test' LIMIT 1"
)->fetch();
$assert((bool) $reviewer, 'Fake program reviewer should exist.');
$assert(($reviewer['staff_role'] ?? '') === 'program_coordinator', 'Fake reviewer should be a program coordinator.');

$recipient = $pdo->query(
    "SELECT * FROM users WHERE email = 'avery.johnson@example.test' LIMIT 1"
)->fetch();
$assert((bool) $recipient, 'Fake recipient account should exist.');
$assert(($recipient['person_type'] ?? '') === 'student', 'Fake recipient should be a student user.');
$assert(!empty($recipient['student_id']), 'Fake recipient should be linked to a student record.');

$award = $pdo->query(
    "SELECT * FROM awards
     WHERE student_id = " . (int) ($recipient['student_id'] ?? 0) . "
       AND status = 'notified'
     LIMIT 1"
)->fetch();
$assert((bool) $award, 'Fake recipient should have a notified award.');

if ($award) {
    $dist = $pdo->prepare(
        'SELECT COUNT(*) AS rows_count, SUM(amount) AS total
         FROM award_distributions WHERE award_id = ?'
    );
    $dist->execute([(int) $award['id']]);
    $distribution = $dist->fetch();

    $assert((int) $distribution['rows_count'] === 2, 'Seeded $2,000 award should have Fall and Spring distributions.');
    $assert(abs((float) $distribution['total'] - 2000.00) < 0.005, 'Seeded award distributions should total $2,000.');
}

$tables = [
    'thank_you_reminder_notifications',
    'user_uica_access',
    'cycle_exports',
    'historical_imports',
    'historical_awards',
    'recipient_activation_tokens',
];

foreach ($tables as $table) {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
    );
    $stmt->execute([$table]);
    $assert((int) $stmt->fetchColumn() === 1, "Expected table {$table} should exist.");
}

if ($reviewer && $cycle) {
    $access = new UicaAccessService($pdo);
    $ids = $access->accessibleScholarshipIds(
        (int) $reviewer['id'],
        $reviewer['staff_role'],
        (int) $cycle['id']
    );
    $assert(is_array($ids), 'Program reviewer should not receive unrestricted UICA review access.');
}

if ($failures !== []) {
    fwrite(STDERR, "Integration failures:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "Seeded workflow integration checks passed.\n");

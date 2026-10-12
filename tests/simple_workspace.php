<?php

declare(strict_types=1);

use App\Database\Connection;
use App\Services\Imports\SimpleAllocationImportService;

require dirname(__DIR__) . '/app/bootstrap.php';

$pdo = Connection::get();
$cycle = $pdo->query("SELECT * FROM academic_cycles WHERE is_current = 1 LIMIT 1")->fetch();
$unit = $pdo->query("SELECT id, name FROM org_units WHERE unit_type = 'department' AND active = 1 ORDER BY id LIMIT 1")->fetch();

if (!$cycle || !$unit) {
    fwrite(STDERR, "Missing seeded cycle or department.\n");
    exit(1);
}

$service = new SimpleAllocationImportService($pdo);
$path = tempnam(sys_get_temp_dir(), 'educ-simple-');
if ($path === false) {
    throw new RuntimeException('Could not create temporary test file.');
}

$makeFile = static function (string $year, string $amount) use ($path, $unit): void {
    $fp = fopen($path, 'wb');
    fputcsv($fp, SimpleAllocationImportService::HEADERS);
    fputcsv($fp, [
        $year, 'TEST-SIMPLE-2027', 'Test Simplified Allocation', '1000.00',
        $unit['name'], '', 'Recruitment', $amount, '2', '500.00',
    ]);
    fclose($fp);
};

try {
    $makeFile((string)$cycle['label'], '1000.00');
    $initial = $service->import($path, 'simple-test.csv', $cycle);
    if ($initial['created'] !== 1) {
        throw new RuntimeException('First import should create one allocation.');
    }
    $again = $service->import($path, 'simple-test.csv', $cycle);
    if ($again['updated'] !== 1) {
        throw new RuntimeException('Reimport should update, not duplicate, the allocation.');
    }
    $stmt = $pdo->query(
        "SELECT ca.authorized_new_amount, ca.recruiting_pool_name
         FROM cycle_allocations ca
         JOIN cycle_scholarships cs ON cs.id = ca.cycle_scholarship_id
         JOIN scholarships s ON s.id = cs.scholarship_id
         WHERE s.uica_account_number = 'TEST-SIMPLE-2027'"
    );
    $rows = $stmt->fetchAll();
    if (count($rows) !== 1 || (float)$rows[0]['authorized_new_amount'] !== 1000.0
        || $rows[0]['recruiting_pool_name'] !== 'Recruitment') {
        throw new RuntimeException('Expected exactly one preserved recruitment allocation.');
    }

    $makeFile((string)$cycle['label'], '1100.00');
    $failed = false;
    try {
        $service->import($path, 'simple-test.csv', $cycle);
    } catch (RuntimeException) {
        $failed = true;
    }
    if (!$failed) {
        throw new RuntimeException('Authority-overrun file should be rejected.');
    }
    $value = $pdo->query(
        "SELECT ca.authorized_new_amount FROM cycle_allocations ca
         JOIN cycle_scholarships cs ON cs.id = ca.cycle_scholarship_id
         JOIN scholarships s ON s.id = cs.scholarship_id
         WHERE s.uica_account_number = 'TEST-SIMPLE-2027'"
    )->fetchColumn();
    if ((float)$value !== 1000.0) {
        throw new RuntimeException('Rejected import must not modify the allocation.');
    }

    $makeFile('NOT-' . (string)$cycle['label'], '1000.00');
    $failed = false;
    try {
        $service->import($path, 'simple-test.csv', $cycle);
    } catch (RuntimeException) {
        $failed = true;
    }
    if (!$failed) {
        throw new RuntimeException('Wrong-cycle file should be rejected.');
    }
    fwrite(STDOUT, "Simple award workspace import checks passed.\n");
} finally {
    @unlink($path);
}

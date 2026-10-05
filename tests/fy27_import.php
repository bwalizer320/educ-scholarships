<?php

declare(strict_types=1);

use App\Database\Connection;
use App\Database\Fy27ScholarshipSeeder;
use App\Storage\LocalFileStorage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

require dirname(__DIR__) . '/app/bootstrap.php';

$pdo = Connection::get();
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$book = new Spreadsheet();
$sch = $book->getActiveSheet();
$sch->setTitle('FY27 Schols - Simplified');
$sch->fromArray([
    ['FY27 College of Education Scholarship Funds to Award'],
    ['Fund ID','Fund Name','Endowed/ Invested','Donor Rpt','FY27 Award Total','# & $ of Awards','Dept/Area','UG/GRAD','Summarized Donor Intent'],
    ['TEST-FUND-1','Shared Fund - Alpha','N','N',6000,'2 awards of $3,000','T&L','UG','Alpha criteria.'],
    ['TEST-FUND-1','Shared Fund - Beta','N','N',1500,'1 award','T&L','UG','Beta criteria.'],
    ['TEST-FUND-2','Standalone Scholarship','Y','Y',5000,'1 award','P&Q','GRAD','Standalone criteria.'],
]);

$spring = $book->createSheet();
$spring->setTitle('FY27 Spring Awards - Simplified');
$spring->fromArray([
    ['FY27 College of Education Student Awards'],
    ['Fund ID','Fund Name','Endowed/ Invested','Donor Rpt','FY27 Award Total','# & $ of Awards','Dept','UG/GRAD','Summarized Donor Intent'],
    ['TEST-FUND-3','Spring Achievement Award','Y','N',1000,'1 award','EPLS','GRAD','Spring award criteria.'],
]);

$all = $book->createSheet();
$all->setTitle('FY27 Schols - All data');
$all->fromArray([
    [],
    ['Fund ID','Fund Name','Endowment','Balance','FY26 Payout Projection','FY27 Payout Projection','Donor Intent Text','FY27 Award Total','# & $ of Awards','Dept','UG/GRAD','Summarized Donor Intent'],
    ['TEST-FUND-1','Shared Fund - Alpha',null,null,null,null,null,6000,'2 awards of $3,000','T&L','UG','Alpha criteria.'],
    ['TEST-FUND-1','Shared Fund - Beta',null,null,null,null,null,1500,'1 award','T&L','UG','Beta criteria.'],
    ['TEST-FUND-2','Standalone Scholarship','P',25000,1200,1250,'Full standalone donor intent.',5000,'1 award','P&Q','GRAD','Standalone criteria.'],
]);

$springAll = $book->createSheet();
$springAll->setTitle('FY27 Spring Awards - All data');
$springAll->fromArray([
    ['Fund ID','Fund Name','Endowment','Balance','FY26 Payout Projection','FY27 Payout Projection','Donor Intent Text','FY27 Award Total','# & $ of Awards','Dept','UG/GRAD','Summarized Donor Intent'],
    ['TEST-FUND-3','Spring Achievement Award','P',10000,400,425,'Full spring donor intent.',1000,'1 award','EPLS','GRAD','Spring award criteria.'],
]);

$master = $book->createSheet();
$master->setTitle('UICA Account Bal and Activities');
$master->fromArray([
    [null,null,null,null,'Full list of COE UICA Scholarship Accounts'],
    ['Fund ID','Fund Name','Endowment','Status','Investment Pool','Spendable Cash','Endowed Investment','Balance','Next FY Payout Projection','Comments','Donor Intent Text'],
    ['TEST-FUND-1','Shared Fund','Q','A','Long Term Pool',10000,50000,60000,2500,'Shared account comments.','Shared account donor intent.'],
    ['TEST-FUND-2','Standalone Scholarship','P','A','Long Term Pool',5000,20000,25000,1250,'Standalone comments.','Full standalone donor intent.'],
    ['TEST-FUND-3','Spring Achievement Award','P','A','Long Term Pool',2000,8000,10000,425,'Spring comments.','Full spring donor intent.'],
]);

$path = sys_get_temp_dir() . '/fy27-scholarship-import-test.xlsx';
(new Xlsx($book))->save($path);
$book->disconnectWorksheets();

try {
    $result = (new Fy27ScholarshipSeeder($pdo, new LocalFileStorage()))->run($path);

    $assert((int)$result['authority_rows'] === 4, 'FY27 importer should load four award rows.');
    $assert(abs((float)$result['authority_total'] - 13500.00) < 0.005, 'FY27 imported authority total should be $13,500.');
    $assert((int)$result['source_rows'] === 11, 'FY27 importer should preserve all simplified, all-data, and UICA source rows.');

    $duplicateCount = (int)$pdo->query(
        "SELECT COUNT(*) FROM scholarships WHERE uica_account_number = 'TEST-FUND-1'"
    )->fetchColumn();
    $assert($duplicateCount === 3, 'Shared UICA fund should support a master catalog record plus two distinct award pools.');

    $cycleId = (int)$result['cycle_id'];
    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM cycle_scholarships cs
         JOIN scholarships s ON s.id = cs.scholarship_id
         WHERE cs.cycle_id = ? AND s.uica_account_number = 'TEST-FUND-1'"
    );
    $stmt->execute([$cycleId]);
    $assert((int)$stmt->fetchColumn() === 2, 'Only the two FY27 Shared Fund award pools should be in the cycle.');

    $snapshot = $pdo->query(
        "SELECT fs.account_balance, fs.next_fy_payout_projection
         FROM cycle_fund_financial_snapshots fs
         JOIN cycle_scholarships cs ON cs.id = fs.cycle_scholarship_id
         JOIN scholarships s ON s.id = cs.scholarship_id
         WHERE s.name = 'Shared Fund - Alpha'
         ORDER BY fs.id DESC LIMIT 1"
    )->fetch();

    $assert((float)($snapshot['account_balance'] ?? 0) === 60000.0, 'Variant award pool should inherit the shared UICA account balance.');
    $assert((float)($snapshot['next_fy_payout_projection'] ?? 0) === 2500.0, 'Variant award pool should inherit the shared UICA payout projection.');

    $sourceCount = (int)$pdo->query(
        "SELECT COUNT(*) FROM annual_fund_source_rows afr
         JOIN annual_authority_imports ai ON ai.id = afr.annual_authority_import_id
         WHERE ai.filename = 'fy27-scholarship-import-test.xlsx'"
    )->fetchColumn();
    $assert($sourceCount === 11, 'Every source workbook row should be retained.');
} finally {
    @unlink($path);
}

if ($failures !== []) {
    fwrite(STDERR, "FY27 import integration failures:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "FY27 workbook import integration checks passed.\n");

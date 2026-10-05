<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\Imports\AnnualAwardAuthorityImportService;
use App\Storage\LocalFileStorage;
use App\Support\Flash;
use App\Support\View;
use RuntimeException;

final class AnnualAwardAuthorityController extends BaseAdminController
{
    public function index(): string
    {
        $this->requireAdmin();
        $cycle = $this->requireCurrentCycle();

        $summaryStmt = $this->pdo->prepare(
            'SELECT COUNT(*) AS fund_count, COALESCE(SUM(total_authorized_amount), 0) AS authority_total
             FROM cycle_scholarships WHERE cycle_id = ?'
        );
        $summaryStmt->execute([(int)$cycle['id']]);
        $summary = $summaryStmt->fetch() ?: ['fund_count' => 0, 'authority_total' => 0];

        $importsStmt = $this->pdo->prepare(
            'SELECT ai.*, u.display_name AS imported_by
             FROM annual_authority_imports ai
             JOIN users u ON u.id = ai.imported_by_user_id
             WHERE ai.cycle_id = ?
             ORDER BY ai.created_at DESC'
        );
        $importsStmt->execute([(int)$cycle['id']]);

        $rows = '';
        foreach ($importsStmt->fetchAll() as $import) {
            $rows .= '<tr>'
                . '<td>' . View::e($import['filename']) . '</td>'
                . '<td>' . View::status($import['status']) . '</td>'
                . '<td>' . (int)$import['imported_count'] . ' / ' . (int)$import['row_count'] . '</td>'
                . '<td>' . (int)$import['created_scholarship_count'] . '</td>'
                . '<td>' . (int)$import['warning_count'] . '</td>'
                . '<td>' . (int)$import['error_count'] . '</td>'
                . '<td>' . View::e($import['imported_by']) . '<br><span class="muted">'
                . View::e(date('M j, Y g:i a', strtotime((string)$import['created_at']))) . '</span></td>'
                . '</tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="7">No annual award-authority workbook has been imported for this cycle.</td></tr>';
        }

        $body = '<div class="page-header"><div><h1>Annual award authority</h1>'
            . '<p>Import the Dean’s Office workbook that establishes how much can be awarded from each fund for this cycle.</p></div>'
            . '<a class="button secondary" href="/admin/planning">Back to planning</a></div>'
            . '<div class="metric-grid">'
            . '<section class="metric-card"><div><span class="metric-label">Funds in cycle</span><strong class="metric-value">' . (int)$summary['fund_count'] . '</strong></div></section>'
            . '<section class="metric-card"><div><span class="metric-label">Authorized total</span><strong class="metric-value">' . View::money($summary['authority_total']) . '</strong></div></section>'
            . '</div>'
            . '<section class="card" style="margin-top:1rem"><h2>Import workbook</h2>'
            . '<p>The importer reads the <strong>Schols - Simplified</strong> and <strong>Spring Awards - Simplified</strong> sheets for annual authority and uses the matching <strong>All data</strong> sheets for administrator-only fund snapshots.</p>'
            . '<div class="notice"><strong>Reviewer privacy:</strong> fund IDs, UICA balances, payout projections, expense history, donor-report contacts, and the full financial snapshot are available only to Scholarship Manager administrators. Faculty reviewers see only scholarship guidance, eligibility criteria, their program recommendation budget, and applicant information.</div>'
            . '<form method="post" action="/admin/award-authority" enctype="multipart/form-data">'
            . View::csrfField()
            . '<label for="authority_file">Annual scholarship workbook</label>'
            . '<input id="authority_file" name="authority_file" type="file" accept=".xls,.xlsx" required>'
            . '<p class="muted">Re-importing updates annual authority and the administrator snapshot. The importer will not reduce a fund below existing confirmed renewal and allocation commitments.</p>'
            . '<div class="form-actions"><button type="submit">Import award authority</button></div></form></section>'
            . '<section class="card" style="margin-top:1rem"><h2>Import history</h2><div class="table-wrap"><table>'
            . '<thead><tr><th>File</th><th>Status</th><th>Imported rows</th><th>New funds</th><th>Warnings</th><th>Errors</th><th>Imported by</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table></div></section>';

        return $this->render('Annual award authority', $body, $cycle);
    }

    public function import(): never
    {
        $this->requireAdmin();
        $this->requirePost();
        $cycle = $this->requireCurrentCycle();

        try {
            $file = $_FILES['authority_file'] ?? null;
            if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Choose a valid annual award-authority workbook.');
            }

            $result = (new AnnualAwardAuthorityImportService($this->pdo, new LocalFileStorage()))->import(
                (int)$cycle['id'],
                (int)$this->auth->userId(),
                (string)$file['tmp_name'],
                (string)$file['name']
            );

            $this->audit(
                'annual_authority.imported',
                'annual_authority_import',
                (int)$result['import_id'],
                (int)$cycle['id'],
                null,
                [
                    'row_count' => (int)$result['row_count'],
                    'created_scholarships' => (int)$result['created_scholarships'],
                ]
            );

            $message = (int)$result['row_count'] . ' fund row(s) imported.';
            if ((int)$result['created_scholarships'] > 0) {
                $message .= ' ' . (int)$result['created_scholarships'] . ' new scholarship record(s) created.';
            }

            Flash::success($message);
        } catch (\Throwable $e) {
            Flash::error($e->getMessage());
        }

        $this->redirect('/admin/award-authority');
    }
}

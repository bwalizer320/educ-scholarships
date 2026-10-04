<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\Imports\EnrollmentImportService;
use App\Services\Imports\MauiEnrollmentParser;
use App\Services\Imports\ProgramMappingService;
use App\Storage\LocalFileStorage;
use App\Support\Flash;
use App\Support\View;
use RuntimeException;

final class EnrollmentController extends BaseAdminController
{
    public function index(): string
    {
        $this->requireAdmin();
        $cycle = $this->requireCurrentCycle();

        $termStmt = $this->pdo->prepare(
            'SELECT * FROM academic_terms WHERE cycle_id = ? ORDER BY calendar_year, season'
        );
        $termStmt->execute([(int) $cycle['id']]);
        $terms = $termStmt->fetchAll();

        $termOptions = '<option value="">Select term</option>';
        foreach ($terms as $term) {
            $termOptions .= '<option value="' . (int) $term['id'] . '">'
                . View::e($term['display_name']) . '</option>';
        }

        $importStmt = $this->pdo->prepare(
            "SELECT ei.*, at.display_name AS term_name, u.display_name AS imported_by
             FROM enrollment_imports ei
             JOIN academic_terms at ON at.id = ei.academic_term_id
             JOIN users u ON u.id = ei.imported_by_user_id
             WHERE ei.cycle_id = ?
             ORDER BY ei.created_at DESC"
        );
        $importStmt->execute([(int) $cycle['id']]);

        $rows = '';
        foreach ($importStmt->fetchAll() as $row) {
            $completed = $row['completed_at']
                ? date('M j, Y g:i a', strtotime((string) $row['completed_at']))
                : '—';

            $rows .= '<tr>'
                . '<td>' . View::e($row['term_name']) . '</td>'
                . '<td>' . View::e($row['filename']) . '</td>'
                . '<td>' . View::status($row['status']) . '</td>'
                . '<td>' . (int) $row['student_count'] . '</td>'
                . '<td>' . View::e($completed) . '</td>'
                . '<td>' . View::e($row['imported_by']) . '</td>'
                . '</tr>';
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="6">No enrollment snapshots have been imported for this cycle.</td></tr>';
        }

        $latestStmt = $this->pdo->prepare(
            "SELECT at.display_name,
                    (SELECT ei.completed_at
                     FROM enrollment_imports ei
                     WHERE ei.academic_term_id = at.id
                       AND ei.status = 'completed'
                       AND ei.is_complete_snapshot = 1
                     ORDER BY ei.completed_at DESC, ei.id DESC
                     LIMIT 1) AS latest_snapshot
             FROM academic_terms at
             WHERE at.cycle_id = ?
             ORDER BY at.calendar_year, at.season"
        );
        $latestStmt->execute([(int) $cycle['id']]);

        $cards = '';
        foreach ($latestStmt->fetchAll() as $term) {
            $latest = $term['latest_snapshot']
                ? date('M j, Y g:i a', strtotime((string) $term['latest_snapshot']))
                : 'No successful snapshot';
            $cards .= '<section class="stat"><span>' . View::e($term['display_name']) . '</span>'
                . '<strong style="font-size:1rem">' . View::e($latest) . '</strong></section>';
        }

        $body = <<<HTML
<div class="page-header">
    <div>
        <h1>Enrollment snapshots</h1>
        <p>Upload a complete MAUI enrollment report. The newest successful complete snapshot becomes authoritative for that term.</p>
    </div>
    <a class="button secondary" href="/admin/applicants/mappings">Program mappings</a>
</div>

<div class="grid" aria-label="Latest successful enrollment snapshots">{$cards}</div>

<section class="card" style="margin-top:1rem">
    <h2>Import MAUI enrollment report</h2>
    <form method="post" action="/admin/enrollment" enctype="multipart/form-data">
        {$this->csrf()}
        <div class="form-grid">
            <div>
                <label for="academic_term_id">Academic term</label>
                <select id="academic_term_id" name="academic_term_id" required>{$termOptions}</select>
            </div>
            <div>
                <label for="enrollment_file">MAUI export</label>
                <input id="enrollment_file" name="enrollment_file" type="file" accept=".xls,.tsv,.txt" required>
            </div>
        </div>
        <p class="muted">The current MAUI report is tab-delimited text even when its filename ends in .xls. A failed import never replaces the previous successful snapshot.</p>
        <div class="form-actions"><button type="submit">Import enrollment snapshot</button></div>
    </form>
</section>

<section class="card" style="margin-top:1rem">
    <h2>Import history</h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Term</th><th>File</th><th>Status</th><th>Students</th><th>Completed</th><th>Imported by</th></tr></thead>
            <tbody>{$rows}</tbody>
        </table>
    </div>
</section>
HTML;

        return $this->render('Enrollment snapshots', $body, $cycle);
    }

    public function import(): never
    {
        $this->requireAdmin();
        $this->requirePost();
        $cycle = $this->requireCurrentCycle();

        try {
            $termId = (int) ($_POST['academic_term_id'] ?? 0);
            $file = $_FILES['enrollment_file'] ?? null;

            $termStmt = $this->pdo->prepare(
                'SELECT id FROM academic_terms WHERE id = ? AND cycle_id = ?'
            );
            $termStmt->execute([$termId, (int) $cycle['id']]);

            if (!$termStmt->fetchColumn()) {
                throw new RuntimeException('Choose a valid academic term for this cycle.');
            }

            if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Choose a valid MAUI enrollment file.');
            }

            $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
            if (!in_array($extension, ['xls','tsv','txt'], true)) {
                throw new RuntimeException('MAUI enrollment import must be the tab-delimited XLS/TSV/TXT export.');
            }

            if ((int) ($file['size'] ?? 0) > 50 * 1024 * 1024) {
                throw new RuntimeException('Enrollment file is larger than 50 MB.');
            }

            $storage = new LocalFileStorage();
            $stored = $storage->storeUploaded(
                (string) $file['tmp_name'],
                (string) $file['name'],
                'enrollment-imports'
            );

            $fileStmt = $this->pdo->prepare(
                'INSERT INTO file_objects (
                    public_id, storage_driver, storage_key, original_filename,
                    mime_type, size_bytes, sha256, uploaded_by_user_id
                 ) VALUES (UUID(), ?, ?, ?, ?, ?, ?, ?)'
            );
            $fileStmt->execute([
                $stored['storage_driver'],
                $stored['storage_key'],
                $stored['original_filename'],
                $stored['mime_type'],
                $stored['size_bytes'],
                $stored['sha256'],
                $this->auth->userId(),
            ]);
            $fileId = (int) $this->pdo->lastInsertId();

            $service = new EnrollmentImportService(
                $this->pdo,
                new MauiEnrollmentParser(),
                new ProgramMappingService($this->pdo)
            );

            $result = $service->import(
                (int) $cycle['id'],
                $termId,
                $stored['path'],
                (string) $file['name'],
                (int) $this->auth->userId(),
                $fileId
            );

            $this->audit(
                'enrollment_import.processed',
                'enrollment_import',
                (int) $result['import_id'],
                (int) $cycle['id'],
                null,
                $result
            );

            if ($result['status'] === 'needs_mapping') {
                Flash::error(
                    'The snapshot was not made authoritative because one or more MAUI programs need mapping. Resolve the program mappings and upload the report again.'
                );
                $this->redirect('/admin/applicants/mappings');
            }

            Flash::success(
                'Enrollment snapshot completed: ' . $result['student_count']
                . ' students across ' . $result['row_count'] . ' source rows.'
            );
        } catch (\Throwable $e) {
            Flash::error($e->getMessage());
        }

        $this->redirect('/admin/enrollment');
    }

    private function csrf(): string
    {
        return View::csrfField();
    }
}

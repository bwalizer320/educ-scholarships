<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthService;
use App\Services\Recipients\UicaAccessService;
use App\Storage\LocalFileStorage;
use App\Support\View;
use PDO;
use RuntimeException;
use ZipArchive;

final class ThankYouReviewController
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AuthService $auth,
        private readonly UicaAccessService $access
    ) {
    }

    public function index(): string
    {
        $cycle = $this->requireStaffCycle();
        $ids = $this->access->accessibleScholarshipIds(
            (int) $this->auth->userId(),
            $this->auth->staffRole(),
            (int) $cycle['id']
        );

        if ($ids !== null && $ids === []) {
            http_response_code(403);
            throw new RuntimeException('No thank-you review access has been assigned to your account.');
        }

        $where = '';
        $params = [(int) $cycle['id']];
        if (is_array($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $where = " AND s.id IN ({$placeholders})";
            array_push($params, ...$ids);
        }

        $uica = trim((string) ($_GET['uica'] ?? ''));
        if ($uica !== '') {
            $where .= ' AND s.uica_account_number = ?';
            $params[] = $uica;
        }

        $stmt = $this->pdo->prepare(
            "SELECT ts.id, ts.submission_method, ts.submitted_at, ts.is_late,
                    st.display_name AS student_name, st.university_id,
                    s.id AS scholarship_id, s.name AS scholarship_name, s.uica_account_number
             FROM thank_you_submissions ts
             JOIN awards a ON a.id = ts.award_id
             JOIN students st ON st.id = ts.student_id
             JOIN cycle_scholarships cs ON cs.id = a.cycle_scholarship_id
             JOIN scholarships s ON s.id = cs.scholarship_id
             WHERE a.cycle_id = ? {$where}
             ORDER BY s.uica_account_number, s.name, st.last_name, st.first_name"
        );
        $stmt->execute($params);

        $rows = '';
        foreach ($stmt->fetchAll() as $row) {
            $rows .= '<tr>'
                . '<td>' . View::e($row['uica_account_number']) . '</td>'
                . '<td>' . View::e($row['scholarship_name']) . '</td>'
                . '<td><strong>' . View::e($row['student_name']) . '</strong><br><span class="muted">' . View::e($row['university_id']) . '</span></td>'
                . '<td>' . View::e(ucwords(str_replace('_',' ',$row['submission_method']))) . '</td>'
                . '<td>' . View::e(date('M j, Y g:i a', strtotime((string) $row['submitted_at']))) . '</td>'
                . '<td>' . ((int) $row['is_late'] === 1 ? View::status('warning') : View::status('completed')) . '</td>'
                . '<td><a class="button secondary small" href="/thank-yous/review/' . (int) $row['id'] . '/download">Download</a></td>'
                . '</tr>';
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="7">No thank-you letters match this view.</td></tr>';
        }

        $uicaStmt = $this->pdo->prepare(
            "SELECT DISTINCT s.uica_account_number, s.name, s.id
             FROM thank_you_submissions ts
             JOIN awards a ON a.id = ts.award_id
             JOIN cycle_scholarships cs ON cs.id = a.cycle_scholarship_id
             JOIN scholarships s ON s.id = cs.scholarship_id
             WHERE a.cycle_id = ? {$where}
             ORDER BY s.uica_account_number"
        );
        $uicaStmt->execute($params);
        $options = '<option value="">All assigned UICA accounts</option>';
        foreach ($uicaStmt->fetchAll() as $row) {
            $options .= '<option value="' . View::e($row['uica_account_number']) . '"'
                . ($uica === $row['uica_account_number'] ? ' selected' : '') . '>'
                . View::e($row['uica_account_number'] . ' — ' . $row['name']) . '</option>';
        }

        $zip = $uica !== ''
            ? '<a class="button" href="/thank-yous/review/zip?uica=' . rawurlencode($uica) . '">Download UICA ZIP</a>'
            : '';

        $body = '<div class="page-header"><div><h1>Thank-you letter review</h1>'
            . '<p>Review and download the thank-you letters assigned to your account.</p></div>' . $zip . '</div>'
            . '<form method="get" action="/thank-yous/review" class="card"><label for="uica">UICA account</label>'
            . '<div class="actions"><select id="uica" name="uica" style="max-width:34rem">' . $options . '</select>'
            . '<button type="submit">Apply filter</button></div></form>'
            . '<div class="table-wrap" style="margin-top:1rem"><table>'
            . '<thead><tr><th>UICA</th><th>Scholarship</th><th>Student</th><th>Method</th><th>Submitted</th><th>Timing</th><th>Download</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table></div>';

        return View::layout(
            'Thank-you review',
            $body,
            $this->auth->displayName(),
            $this->auth->staffRole(),
            $cycle['label']
        );
    }

    public function download(array $params): never
    {
        $cycle = $this->requireStaffCycle();
        $id = (int) ($params['id'] ?? 0);

        $stmt = $this->pdo->prepare(
            "SELECT ts.id, s.id AS scholarship_id,
                    COALESCE(rfo.storage_key, fo.storage_key) AS storage_key,
                    COALESCE(rfo.original_filename, fo.original_filename) AS original_filename,
                    COALESCE(rfo.mime_type, fo.mime_type) AS mime_type
             FROM thank_you_submissions ts
             JOIN awards a ON a.id = ts.award_id
             JOIN cycle_scholarships cs ON cs.id = a.cycle_scholarship_id
             JOIN scholarships s ON s.id = cs.scholarship_id
             LEFT JOIN file_objects fo ON fo.id = ts.uploaded_file_id
             LEFT JOIN file_objects rfo ON rfo.id = ts.rendered_file_id
             WHERE ts.id = ? AND a.cycle_id = ?"
        );
        $stmt->execute([$id, (int) $cycle['id']]);
        $row = $stmt->fetch();

        if (!$row || !$row['storage_key'] || !$this->access->canReview(
            (int) $this->auth->userId(),
            $this->auth->staffRole(),
            (int) $row['scholarship_id'],
            (int) $cycle['id']
        )) {
            http_response_code(404);
            exit('Thank-you letter not available.');
        }

        $this->pdo->prepare(
            "INSERT INTO thank_you_downloads (
                thank_you_submission_id, downloaded_by_user_id, download_type
             ) VALUES (?, ?, 'single')"
        )->execute([$id, $this->auth->userId()]);

        $this->stream($row);
    }

    public function zip(): never
    {
        $cycle = $this->requireStaffCycle();
        $uica = trim((string) ($_GET['uica'] ?? ''));

        if ($uica === '') {
            http_response_code(400);
            exit('Choose a UICA account first.');
        }

        $scholarshipStmt = $this->pdo->prepare(
            'SELECT id FROM scholarships WHERE uica_account_number = ? LIMIT 1'
        );
        $scholarshipStmt->execute([$uica]);
        $scholarshipId = $scholarshipStmt->fetchColumn();

        if ($scholarshipId === false || !$this->access->canReview(
            (int) $this->auth->userId(),
            $this->auth->staffRole(),
            (int) $scholarshipId,
            (int) $cycle['id']
        )) {
            http_response_code(403);
            exit('You do not have access to that UICA account.');
        }

        $stmt = $this->pdo->prepare(
            "SELECT ts.id, st.display_name AS student_name, s.name AS scholarship_name,
                    COALESCE(rfo.storage_key, fo.storage_key) AS storage_key,
                    COALESCE(rfo.original_filename, fo.original_filename) AS original_filename
             FROM thank_you_submissions ts
             JOIN awards a ON a.id = ts.award_id
             JOIN students st ON st.id = ts.student_id
             JOIN cycle_scholarships cs ON cs.id = a.cycle_scholarship_id
             JOIN scholarships s ON s.id = cs.scholarship_id
             LEFT JOIN file_objects fo ON fo.id = ts.uploaded_file_id
             LEFT JOIN file_objects rfo ON rfo.id = ts.rendered_file_id
             WHERE a.cycle_id = ? AND s.uica_account_number = ?
             ORDER BY st.last_name, st.first_name"
        );
        $stmt->execute([(int) $cycle['id'], $uica]);
        $rows = $stmt->fetchAll();

        if ($rows === []) {
            http_response_code(404);
            exit('No thank-you letters found.');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'uica_');
        if ($tmp === false) {
            throw new RuntimeException('Could not create ZIP workspace.');
        }
        $zipPath = $tmp . '.zip';
        rename($tmp, $zipPath);

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create thank-you ZIP.');
        }

        $storage = new LocalFileStorage();
        foreach ($rows as $row) {
            if (!$row['storage_key']) {
                continue;
            }
            $source = $storage->path((string) $row['storage_key']);
            $ext = pathinfo((string) $row['original_filename'], PATHINFO_EXTENSION) ?: 'pdf';
            $student = trim(preg_replace('/[^A-Za-z0-9]+/', '_', (string) $row['student_name']) ?: 'Student', '_');
            $scholarship = trim(preg_replace('/[^A-Za-z0-9]+/', '_', (string) $row['scholarship_name']) ?: 'Scholarship', '_');
            $zip->addFile($source, $scholarship . '/' . $student . '_Thank_You.' . $ext);

            $this->pdo->prepare(
                "INSERT INTO thank_you_downloads (
                    thank_you_submission_id, downloaded_by_user_id, download_type
                 ) VALUES (?, ?, 'zip')"
            )->execute([(int) $row['id'], $this->auth->userId()]);
        }
        $zip->close();

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="UICA_' . preg_replace('/[^A-Za-z0-9_-]+/','_',$uica) . '_Thank_You_Letters.zip"');
        header('Content-Length: ' . (string) filesize($zipPath));
        header('X-Content-Type-Options: nosniff');
        readfile($zipPath);
        @unlink($zipPath);
        exit;
    }

    private function requireStaffCycle(): array
    {
        if (!$this->auth->check() || $this->auth->personType() !== 'staff') {
            header('Location: /login');
            exit;
        }

        $cycle = $this->pdo->query(
            'SELECT * FROM academic_cycles WHERE is_current = 1 LIMIT 1'
        )->fetch();

        if (!$cycle) {
            throw new RuntimeException('No current academic cycle is configured.');
        }

        return $cycle;
    }

    private function stream(array $file): never
    {
        $path = (new LocalFileStorage())->path((string) $file['storage_key']);
        header('Content-Type: ' . $file['mime_type']);
        header('Content-Length: ' . (string) filesize($path));
        header('Content-Disposition: attachment; filename="' . str_replace('"','',(string)$file['original_filename']) . '"');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }
}

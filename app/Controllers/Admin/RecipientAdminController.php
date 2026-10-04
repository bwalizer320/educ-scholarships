<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\Awards\AwardService;
use App\Services\Awards\DistributionService;
use App\Storage\LocalFileStorage;
use App\Support\Flash;
use App\Support\View;
use RuntimeException;
use ZipArchive;

final class RecipientAdminController extends BaseAdminController
{
    public function distributionRequests(): string
    {
        $this->requireAdmin();
        $cycle = $this->requireCurrentCycle();

        $stmt = $this->pdo->prepare(
            "SELECT dr.*, st.display_name AS student_name, st.university_id,
                    s.name AS scholarship_name, a.total_amount,
                    at.display_name AS requested_term_name
             FROM distribution_requests dr
             JOIN awards a ON a.id = dr.award_id
             JOIN students st ON st.id = dr.student_id
             JOIN cycle_scholarships cs ON cs.id = a.cycle_scholarship_id
             JOIN scholarships s ON s.id = cs.scholarship_id
             JOIN academic_terms at ON at.id = dr.requested_term_id
             WHERE a.cycle_id = ?
             ORDER BY FIELD(dr.status,'pending','approved','declined'), dr.requested_at"
        );
        $stmt->execute([(int) $cycle['id']]);

        $rows = '';
        foreach ($stmt->fetchAll() as $row) {
            $actions = '';
            if ($row['status'] === 'pending') {
                $actions = '<form method="post" action="/admin/distribution-requests/' . (int) $row['id'] . '">'
                    . View::csrfField()
                    . '<div class="actions"><button class="small" name="action" value="approve">Approve</button>'
                    . '<button class="secondary small" name="action" value="decline">Decline</button></div></form>';
            }

            $rows .= '<tr><td>' . View::e($row['scholarship_name']) . '</td>'
                . '<td><strong>' . View::e($row['student_name']) . '</strong><br><span class="muted">' . View::e($row['university_id']) . '</span></td>'
                . '<td class="num">' . View::money($row['total_amount']) . '</td>'
                . '<td>' . View::e($row['requested_term_name']) . '</td>'
                . '<td>' . View::e(ucwords(str_replace('_',' ',$row['reason']))) . '</td>'
                . '<td>' . View::e($row['student_note'] ?? '') . '</td>'
                . '<td>' . View::status($row['status']) . '</td>'
                . '<td>' . $actions . '</td></tr>';
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="8">No recipient distribution requests for this cycle.</td></tr>';
        }

        $body = '<div class="page-header"><div><h1>Distribution requests</h1>'
            . '<p>Approve eligible single-term requests for graduating or student-teaching recipients.</p></div></div>'
            . '<div class="table-wrap"><table><thead><tr><th>Scholarship</th><th>Student</th><th class="num">Award</th><th>Requested term</th><th>Reason</th><th>Student note</th><th>Status</th><th>Action</th></tr></thead><tbody>'
            . $rows . '</tbody></table></div>';

        return $this->render('Distribution requests', Flash::render() . $body, $cycle);
    }

    public function reviewDistributionRequest(array $params): never
    {
        $this->requireAdmin();
        $this->requirePost();
        $cycle = $this->requireCurrentCycle();
        $requestId = (int) ($params['id'] ?? 0);
        $action = (string) ($_POST['action'] ?? '');

        try {
            $stmt = $this->pdo->prepare(
                "SELECT dr.*, a.total_amount, a.cycle_id
                 FROM distribution_requests dr
                 JOIN awards a ON a.id = dr.award_id
                 WHERE dr.id = ? AND a.cycle_id = ? AND dr.status = 'pending'"
            );
            $stmt->execute([$requestId, (int) $cycle['id']]);
            $request = $stmt->fetch();

            if (!$request) {
                throw new RuntimeException('Pending distribution request not found.');
            }

            if ($action === 'approve') {
                (new AwardService($this->pdo, new DistributionService()))->replaceDistributions(
                    (int) $request['award_id'],
                    [[
                        'academic_term_id' => (int) $request['requested_term_id'],
                        'amount' => (float) $request['total_amount'],
                        'source' => 'student_request',
                    ]]
                );

                $status = 'approved';
            } elseif ($action === 'decline') {
                $status = 'declined';
            } else {
                throw new RuntimeException('Choose approve or decline.');
            }

            $this->pdo->prepare(
                'UPDATE distribution_requests
                 SET status = ?, reviewed_by_user_id = ?, reviewed_at = NOW()
                 WHERE id = ?'
            )->execute([$status, $this->auth->userId(), $requestId]);

            $this->audit(
                'distribution_request.' . $status,
                'distribution_request',
                $requestId,
                (int) $cycle['id'],
                null,
                ['requested_term_id'=>(int)$request['requested_term_id']]
            );
            Flash::success('Distribution request ' . $status . '.');
        } catch (\Throwable $e) {
            Flash::error($e->getMessage());
        }

        $this->redirect('/admin/distribution-requests');
    }

    public function thankYous(): string
    {
        $this->requireAdmin();
        $cycle = $this->requireCurrentCycle();

        $uica = trim((string) ($_GET['uica'] ?? ''));
        $sql = "SELECT ts.*, st.display_name AS student_name, st.university_id,
                       s.name AS scholarship_name, s.uica_account_number,
                       fo.original_filename AS upload_name,
                       rfo.original_filename AS rendered_name
                FROM thank_you_submissions ts
                JOIN awards a ON a.id = ts.award_id
                JOIN students st ON st.id = ts.student_id
                JOIN cycle_scholarships cs ON cs.id = a.cycle_scholarship_id
                JOIN scholarships s ON s.id = cs.scholarship_id
                LEFT JOIN file_objects fo ON fo.id = ts.uploaded_file_id
                LEFT JOIN file_objects rfo ON rfo.id = ts.rendered_file_id
                WHERE a.cycle_id = ?";
        $params = [(int) $cycle['id']];

        if ($uica !== '') {
            $sql .= ' AND s.uica_account_number = ?';
            $params[] = $uica;
        }
        $sql .= ' ORDER BY s.uica_account_number, s.name, st.last_name, st.first_name';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $rows = '';
        foreach ($stmt->fetchAll() as $row) {
            $rows .= '<tr><td>' . View::e($row['uica_account_number']) . '</td>'
                . '<td>' . View::e($row['scholarship_name']) . '</td>'
                . '<td><strong>' . View::e($row['student_name']) . '</strong><br><span class="muted">' . View::e($row['university_id']) . '</span></td>'
                . '<td>' . View::e(ucwords(str_replace('_',' ',$row['submission_method']))) . '</td>'
                . '<td>' . View::e(date('M j, Y g:i a', strtotime((string) $row['submitted_at']))) . '</td>'
                . '<td>' . ((int)$row['is_late'] === 1 ? View::status('warning') : View::status('completed')) . '</td>'
                . '<td><a class="button secondary small" href="/admin/thank-yous/' . (int) $row['id'] . '/download">Download</a></td></tr>';
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="7">No thank-you submissions match this view.</td></tr>';
        }

        $uicas = $this->pdo->prepare(
            "SELECT DISTINCT s.uica_account_number, s.name
             FROM thank_you_submissions ts
             JOIN awards a ON a.id = ts.award_id
             JOIN cycle_scholarships cs ON cs.id = a.cycle_scholarship_id
             JOIN scholarships s ON s.id = cs.scholarship_id
             WHERE a.cycle_id = ?
             ORDER BY s.uica_account_number"
        );
        $uicas->execute([(int) $cycle['id']]);
        $options = '<option value="">All UICA accounts</option>';
        foreach ($uicas->fetchAll() as $row) {
            $options .= '<option value="' . View::e($row['uica_account_number']) . '"'
                . ($uica === $row['uica_account_number'] ? ' selected' : '') . '>'
                . View::e($row['uica_account_number'] . ' — ' . $row['name']) . '</option>';
        }

        $zipButton = $uica !== ''
            ? '<a class="button" href="/admin/thank-yous/zip?uica=' . rawurlencode($uica) . '">Download UICA ZIP</a>'
            : '';

        $body = '<div class="page-header"><div><h1>Thank-you letters</h1>'
            . '<p>Review recipient submissions and download donor-ready files by UICA account.</p></div>' . $zipButton . '</div>'
            . '<form method="get" action="/admin/thank-yous" class="card"><label for="uica">Filter by UICA account</label>'
            . '<div class="actions"><select id="uica" name="uica" style="max-width:34rem">' . $options . '</select>'
            . '<button type="submit">Apply filter</button></div></form>'
            . '<div class="table-wrap" style="margin-top:1rem"><table><thead><tr><th>UICA</th><th>Scholarship</th><th>Student</th><th>Method</th><th>Submitted</th><th>Timing</th><th>Download</th></tr></thead><tbody>'
            . $rows . '</tbody></table></div>';

        return $this->render('Thank-you letters', Flash::render() . $body, $cycle);
    }

    public function downloadThankYou(array $params): never
    {
        $this->requireAdmin();
        $cycle = $this->requireCurrentCycle();
        $id = (int) ($params['id'] ?? 0);

        $stmt = $this->pdo->prepare(
            "SELECT ts.*, a.cycle_id,
                    COALESCE(rfo.id, fo.id) AS file_id,
                    COALESCE(rfo.storage_key, fo.storage_key) AS storage_key,
                    COALESCE(rfo.original_filename, fo.original_filename) AS original_filename,
                    COALESCE(rfo.mime_type, fo.mime_type) AS mime_type
             FROM thank_you_submissions ts
             JOIN awards a ON a.id = ts.award_id
             LEFT JOIN file_objects fo ON fo.id = ts.uploaded_file_id
             LEFT JOIN file_objects rfo ON rfo.id = ts.rendered_file_id
             WHERE ts.id = ? AND a.cycle_id = ?"
        );
        $stmt->execute([$id, (int) $cycle['id']]);
        $row = $stmt->fetch();

        if (!$row || !$row['storage_key']) {
            http_response_code(404);
            exit('Thank-you file not available.');
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
        $this->requireAdmin();
        $cycle = $this->requireCurrentCycle();
        $uica = trim((string) ($_GET['uica'] ?? ''));

        if ($uica === '') {
            http_response_code(400);
            exit('Choose a UICA account first.');
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
            exit('No thank-you letters found for this UICA account.');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'thanks_');
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
            $extension = pathinfo((string) $row['original_filename'], PATHINFO_EXTENSION) ?: 'pdf';
            $safeStudent = preg_replace('/[^A-Za-z0-9]+/', '_', (string) $row['student_name']) ?: 'Student';
            $safeScholarship = preg_replace('/[^A-Za-z0-9]+/', '_', (string) $row['scholarship_name']) ?: 'Scholarship';
            $zip->addFile($source, trim($safeScholarship,'_') . '/' . trim($safeStudent,'_') . '_Thank_You.' . $extension);

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

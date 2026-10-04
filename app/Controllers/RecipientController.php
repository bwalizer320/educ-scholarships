<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthService;
use App\Http\Csrf;
use App\Pdf\ThankYouRenderer;
use App\Storage\LocalFileStorage;
use App\Support\Flash;
use App\Support\View;
use PDO;
use RuntimeException;

final class RecipientController
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AuthService $auth
    ) {
    }

    public function index(): string
    {
        $studentId = $this->requireStudent();

        $stmt = $this->pdo->prepare(
            "SELECT a.*, s.name AS scholarship_name, ac.label AS cycle_label,
                    ac.thank_you_due_at,
                    (SELECT COUNT(*) FROM thank_you_submissions ts WHERE ts.award_id = a.id) AS thank_you_submitted,
                    (SELECT status FROM distribution_requests dr WHERE dr.award_id = a.id ORDER BY dr.id DESC LIMIT 1) AS distribution_request_status
             FROM awards a
             JOIN cycle_scholarships cs ON cs.id = a.cycle_scholarship_id
             JOIN scholarships s ON s.id = cs.scholarship_id
             JOIN academic_cycles ac ON ac.id = a.cycle_id
             WHERE a.student_id = ?
               AND a.status IN ('notified','ready_to_notify','approved')
             ORDER BY ac.start_year DESC, s.name"
        );
        $stmt->execute([$studentId]);

        $cards = '';
        foreach ($stmt->fetchAll() as $award) {
            $thanks = (int) $award['thank_you_submitted'] > 0
                ? View::status('completed')
                : View::status('not_started');
            $request = $award['distribution_request_status']
                ? View::status($award['distribution_request_status'])
                : '<span class="muted">None</span>';

            $cards .= '<section class="card">'
                . '<div class="page-header"><div><h2>' . View::e($award['scholarship_name']) . '</h2>'
                . '<p>' . View::e($award['cycle_label']) . '</p></div>'
                . '<strong style="font-size:1.4rem">' . View::money($award['total_amount']) . '</strong></div>'
                . '<p><strong>Status:</strong> ' . View::status($award['status']) . '</p>'
                . '<p><strong>Thank-you letter:</strong> ' . $thanks . '</p>'
                . '<p><strong>Distribution request:</strong> ' . $request . '</p>'
                . '<a class="button" href="/portal/awards/' . View::e($award['public_id']) . '">View award</a>'
                . '</section>';
        }

        if ($cards === '') {
            $cards = '<div class="notice">You do not currently have any scholarship awards available in the recipient portal.</div>';
        }

        $body = Flash::render()
            . '<div class="page-header"><div><h1>My scholarship awards</h1><p>Review award details, distribution schedule, and thank-you requirements.</p></div>'
            . '<form method="post" action="/logout">' . View::csrfField() . '<button class="secondary" type="submit">Sign out</button></form></div>'
            . '<div class="grid">' . $cards . '</div>';

        return View::layout('My awards', $body, $this->auth->displayName(), 'student');
    }

    public function award(array $params): string
    {
        $studentId = $this->requireStudent();
        $award = $this->awardForStudent((string) ($params['public'] ?? ''), $studentId);

        $distStmt = $this->pdo->prepare(
            "SELECT ad.*, at.display_name AS term_name
             FROM award_distributions ad
             JOIN academic_terms at ON at.id = ad.academic_term_id
             WHERE ad.award_id = ?
             ORDER BY at.calendar_year, at.season"
        );
        $distStmt->execute([(int) $award['id']]);

        $distRows = '';
        foreach ($distStmt->fetchAll() as $row) {
            $distRows .= '<tr><td>' . View::e($row['term_name']) . '</td><td class="num">'
                . View::money($row['amount']) . '</td><td>' . View::e(ucwords(str_replace('_',' ',$row['source']))) . '</td></tr>';
        }
        if ($distRows === '') {
            $distRows = '<tr><td colspan="3">No distribution schedule has been finalized.</td></tr>';
        }

        $notificationStmt = $this->pdo->prepare(
            "SELECT an.letter_file_id
             FROM award_notifications an
             WHERE an.award_id = ? AND an.status = 'sent'
             ORDER BY an.sent_at DESC, an.id DESC LIMIT 1"
        );
        $notificationStmt->execute([(int) $award['id']]);
        $letterFileId = $notificationStmt->fetchColumn();

        $letterLink = $letterFileId
            ? '<a class="button secondary" href="/portal/awards/' . View::e($award['public_id']) . '/letter">Download award letter</a>'
            : '';

        $requestStmt = $this->pdo->prepare(
            'SELECT dr.*, at.display_name AS term_name
             FROM distribution_requests dr
             JOIN academic_terms at ON at.id = dr.requested_term_id
             WHERE dr.award_id = ?
             ORDER BY dr.id DESC LIMIT 1'
        );
        $requestStmt->execute([(int) $award['id']]);
        $request = $requestStmt->fetch();

        $requestHtml = $this->distributionRequestForm($award, $request);
        $thankYouHtml = $this->thankYouSection($award);

        $body = Flash::render()
            . '<div class="page-header"><div><h1>' . View::e($award['scholarship_name']) . '</h1>'
            . '<p>' . View::e($award['cycle_label']) . ' · ' . View::money($award['total_amount']) . '</p></div>'
            . '<div class="actions"><a class="button secondary" href="/portal">All awards</a>' . $letterLink . '</div></div>'
            . '<section class="card"><h2>Distribution schedule</h2><div class="table-wrap"><table>'
            . '<thead><tr><th>Term</th><th class="num">Amount</th><th>Source</th></tr></thead><tbody>'
            . $distRows . '</tbody></table></div></section>'
            . $requestHtml
            . $thankYouHtml;

        return View::layout($award['scholarship_name'], $body, $this->auth->displayName(), 'student', $award['cycle_label']);
    }

    public function requestDistribution(array $params): never
    {
        $this->requirePost();
        $studentId = $this->requireStudent();
        $award = $this->awardForStudent((string) ($params['public'] ?? ''), $studentId);

        try {
            $termId = (int) ($_POST['requested_term_id'] ?? 0);
            $reason = (string) ($_POST['reason'] ?? '');
            $note = trim((string) ($_POST['student_note'] ?? ''));

            if (!in_array($reason, ['graduating','student_teaching'], true)) {
                throw new RuntimeException('Choose a valid reason for the distribution request.');
            }

            if ($reason === 'graduating' && !(bool) $award['single_semester_allowed_if_graduating']) {
                throw new RuntimeException('This scholarship does not allow a single-semester distribution for graduation.');
            }

            if ($reason === 'student_teaching' && !(bool) $award['student_teaching_required']) {
                throw new RuntimeException('Student-teaching distribution is not enabled for this scholarship.');
            }

            $termStmt = $this->pdo->prepare(
                'SELECT id FROM academic_terms WHERE id = ? AND cycle_id = ?'
            );
            $termStmt->execute([$termId, (int) $award['cycle_id']]);
            if (!$termStmt->fetchColumn()) {
                throw new RuntimeException('Choose a valid term for this award.');
            }

            $pending = $this->pdo->prepare(
                "SELECT id FROM distribution_requests
                 WHERE award_id = ? AND status = 'pending' LIMIT 1"
            );
            $pending->execute([(int) $award['id']]);
            if ($pending->fetchColumn()) {
                throw new RuntimeException('A distribution request is already pending review.');
            }

            $stmt = $this->pdo->prepare(
                "INSERT INTO distribution_requests (
                    award_id, student_id, requested_term_id, reason, student_note, status
                 ) VALUES (?, ?, ?, ?, ?, 'pending')"
            );
            $stmt->execute([
                $award['id'],
                $studentId,
                $termId,
                $reason,
                $note !== '' ? $note : null,
            ]);

            Flash::success('Your distribution request was submitted to the Dean’s Office.');
        } catch (\Throwable $e) {
            Flash::error($e->getMessage());
        }

        $this->redirect('/portal/awards/' . $award['public_id']);
    }

    public function submitThankYou(array $params): never
    {
        $this->requirePost();
        $studentId = $this->requireStudent();
        $award = $this->awardForStudent((string) ($params['public'] ?? ''), $studentId);

        try {
            $method = (string) ($_POST['submission_method'] ?? 'rich_text');
            $deadline = $award['thank_you_due_at'];

            if (!$deadline) {
                throw new RuntimeException('A thank-you deadline has not been configured for this award cycle.');
            }

            $submittedAt = date('Y-m-d H:i:s');
            $isLate = strtotime($submittedAt) > strtotime((string) $deadline) ? 1 : 0;
            $uploadedFileId = null;
            $renderedFileId = null;
            $richText = null;

            if ($method === 'upload') {
                $file = $_FILES['thank_you_file'] ?? null;
                if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    throw new RuntimeException('Choose a thank-you letter file to upload.');
                }

                $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
                if (!in_array($extension, ['pdf','doc','docx'], true)) {
                    throw new RuntimeException('Thank-you uploads must be PDF, DOC, or DOCX.');
                }

                if ((int) ($file['size'] ?? 0) > 10 * 1024 * 1024) {
                    throw new RuntimeException('Thank-you file is larger than 10 MB.');
                }

                $stored = (new LocalFileStorage())->storeUploaded(
                    (string) $file['tmp_name'],
                    (string) $file['name'],
                    'thank-you-uploads'
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
                $uploadedFileId = (int) $this->pdo->lastInsertId();
            } else {
                $method = 'rich_text';
                $richText = trim((string) ($_POST['rich_text_html'] ?? ''));
                if (mb_strlen(strip_tags($richText)) < 40) {
                    throw new RuntimeException('Please enter a complete thank-you message before submitting.');
                }

                $safeScholarship = preg_replace('/[^A-Za-z0-9]+/', '_', $award['scholarship_name']) ?: 'Scholarship';
                $renderedFileId = (new ThankYouRenderer($this->pdo, new LocalFileStorage()))
                    ->renderAndStore(
                        (int) $this->auth->userId(),
                        $award['student_name'],
                        $richText,
                        trim($safeScholarship, '_') . '_Thank_You.pdf'
                    );
            }

            $existing = $this->pdo->prepare('SELECT id FROM thank_you_submissions WHERE award_id = ?');
            $existing->execute([(int) $award['id']]);
            $submissionId = $existing->fetchColumn();

            if ($submissionId === false) {
                $stmt = $this->pdo->prepare(
                    "INSERT INTO thank_you_submissions (
                        award_id, student_id, submission_method, rich_text_html,
                        uploaded_file_id, submitted_at, deadline_at_snapshot,
                        is_late, rendered_file_id
                     ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->execute([
                    $award['id'],$studentId,$method,$richText,$uploadedFileId,
                    $submittedAt,$deadline,$isLate,$renderedFileId
                ]);
            } else {
                $stmt = $this->pdo->prepare(
                    "UPDATE thank_you_submissions
                     SET submission_method = ?, rich_text_html = ?, uploaded_file_id = ?,
                         submitted_at = ?, is_late = ?, rendered_file_id = ?, reviewer_notes = NULL
                     WHERE id = ?"
                );
                $stmt->execute([
                    $method,$richText,$uploadedFileId,$submittedAt,$isLate,$renderedFileId,$submissionId
                ]);
            }

            Flash::success($isLate ? 'Thank-you letter submitted and marked late.' : 'Thank-you letter submitted.');
        } catch (\Throwable $e) {
            Flash::error($e->getMessage());
        }

        $this->redirect('/portal/awards/' . $award['public_id']);
    }

    public function letter(array $params): never
    {
        $studentId = $this->requireStudent();
        $award = $this->awardForStudent((string) ($params['public'] ?? ''), $studentId);

        $stmt = $this->pdo->prepare(
            "SELECT fo.*
             FROM award_notifications an
             JOIN file_objects fo ON fo.id = an.letter_file_id
             WHERE an.award_id = ? AND an.status = 'sent'
             ORDER BY an.sent_at DESC, an.id DESC LIMIT 1"
        );
        $stmt->execute([(int) $award['id']]);
        $file = $stmt->fetch();

        if (!$file) {
            http_response_code(404);
            exit('Award letter not available.');
        }

        $this->streamFile($file);
    }

    private function distributionRequestForm(array $award, ?array $request): string
    {
        if ($request && $request['status'] === 'pending') {
            return '<section class="card" style="margin-top:1rem"><h2>Distribution request</h2>'
                . '<p>Your request for <strong>' . View::e($request['term_name']) . '</strong> is '
                . View::status('pending') . '.</p></section>';
        }

        $canGraduate = (bool) $award['single_semester_allowed_if_graduating'];
        $canStudentTeach = (bool) $award['student_teaching_required'];

        if (!$canGraduate && !$canStudentTeach) {
            return '';
        }

        $termStmt = $this->pdo->prepare(
            'SELECT id, display_name FROM academic_terms WHERE cycle_id = ? ORDER BY calendar_year, season'
        );
        $termStmt->execute([(int) $award['cycle_id']]);
        $options = '';
        foreach ($termStmt->fetchAll() as $term) {
            $options .= '<option value="' . (int) $term['id'] . '">' . View::e($term['display_name']) . '</option>';
        }

        $reasonOptions = '';
        if ($canGraduate) {
            $reasonOptions .= '<option value="graduating">I am graduating and need the award in one semester</option>';
        }
        if ($canStudentTeach) {
            $reasonOptions .= '<option value="student_teaching">This is my student-teaching semester</option>';
        }

        return '<section class="card" style="margin-top:1rem"><h2>Request a different distribution term</h2>'
            . '<p>If you qualify for one of the reasons below, you may request that the full award be placed in a single term. The Dean’s Office must approve the request.</p>'
            . '<form method="post" action="/portal/awards/' . View::e($award['public_id']) . '/distribution-request">'
            . View::csrfField()
            . '<div class="form-grid"><div><label for="reason">Reason</label><select id="reason" name="reason" required>'
            . $reasonOptions . '</select></div><div><label for="requested_term_id">Requested term</label><select id="requested_term_id" name="requested_term_id" required>'
            . $options . '</select></div></div>'
            . '<label for="student_note">Note (optional)</label><textarea id="student_note" name="student_note"></textarea>'
            . '<div class="form-actions"><button type="submit">Submit distribution request</button></div></form></section>';
    }

    private function thankYouSection(array $award): string
    {
        $stmt = $this->pdo->prepare('SELECT * FROM thank_you_submissions WHERE award_id = ?');
        $stmt->execute([(int) $award['id']]);
        $submission = $stmt->fetch();

        $status = $submission
            ? '<p>' . View::status('completed') . ' Submitted '
                . View::e(date('M j, Y g:i a', strtotime((string) $submission['submitted_at'])))
                . ((int) $submission['is_late'] === 1 ? ' · <strong>Late</strong>' : '') . '</p>'
            : '<p>' . View::status('not_started') . '</p>';

        $deadline = $award['thank_you_due_at']
            ? date('F j, Y', strtotime((string) $award['thank_you_due_at']))
            : 'Not set';

        return '<section class="card" style="margin-top:1rem"><h2>Thank-you letter</h2>'
            . '<p>Deadline: <strong>' . View::e($deadline) . '</strong></p>' . $status
            . '<p>Write your message below or upload a completed PDF/DOC/DOCX thank-you letter. Submitting again replaces your current submission.</p>'
            . '<form method="post" action="/portal/awards/' . View::e($award['public_id']) . '/thank-you" enctype="multipart/form-data">'
            . View::csrfField()
            . '<label><input type="radio" name="submission_method" value="rich_text" checked> Write my thank-you message here</label>'
            . '<label for="rich_text_html">Thank-you message</label><textarea id="rich_text_html" name="rich_text_html"></textarea>'
            . '<label><input type="radio" name="submission_method" value="upload"> Upload a completed letter</label>'
            . '<label for="thank_you_file">Thank-you file</label><input id="thank_you_file" name="thank_you_file" type="file" accept=".pdf,.doc,.docx">'
            . '<div class="form-actions"><button type="submit">Submit thank-you letter</button></div></form></section>';
    }

    private function awardForStudent(string $publicId, int $studentId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT a.*, s.name AS scholarship_name, ac.label AS cycle_label,
                    ac.thank_you_due_at, ac.distribution_change_due_at,
                    sar.student_teaching_required, sar.single_semester_allowed_if_graduating,
                    st.display_name AS student_name
             FROM awards a
             JOIN students st ON st.id = a.student_id
             JOIN cycle_scholarships cs ON cs.id = a.cycle_scholarship_id
             JOIN scholarships s ON s.id = cs.scholarship_id
             JOIN academic_cycles ac ON ac.id = a.cycle_id
             LEFT JOIN scholarship_award_rules sar ON sar.intent_version_id = cs.intent_version_id
             WHERE a.public_id = ? AND a.student_id = ?
               AND a.status IN ('approved','ready_to_notify','notified')"
        );
        $stmt->execute([$publicId, $studentId]);
        $award = $stmt->fetch();

        if (!$award) {
            http_response_code(404);
            throw new RuntimeException('Award not found.');
        }

        return $award;
    }

    private function requireStudent(): int
    {
        if (!$this->auth->check() || $this->auth->personType() !== 'student') {
            header('Location: /login');
            exit;
        }

        $stmt = $this->pdo->prepare('SELECT student_id FROM users WHERE id = ? AND active = 1');
        $stmt->execute([$this->auth->userId()]);
        $studentId = $stmt->fetchColumn();

        if ($studentId === false || $studentId === null) {
            throw new RuntimeException('Your recipient account is not linked to a student record.');
        }

        return (int) $studentId;
    }

    private function requirePost(): void
    {
        Csrf::assertValid($_POST['_csrf'] ?? null);
    }

    private function streamFile(array $file): never
    {
        $path = (new LocalFileStorage())->path((string) $file['storage_key']);
        header('Content-Type: ' . $file['mime_type']);
        header('Content-Length: ' . (string) filesize($path));
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $file['original_filename']) . '"');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    private function redirect(string $path): never
    {
        header('Location: ' . $path);
        exit;
    }
}

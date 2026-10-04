<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Pdf\LetterRenderer;
use App\Services\Notifications\NotificationService;
use App\Storage\LocalFileStorage;
use App\Support\Flash;
use App\Support\View;
use RuntimeException;

final class NotificationController extends BaseAdminController
{
    public function ready(): string
    {
        $this->requireAdmin();
        $cycle = $this->requireCurrentCycle();

        $stmt = $this->pdo->prepare(
            "SELECT a.id, a.award_origin, a.total_amount, st.display_name AS student_name,
                    st.email, s.name AS scholarship_name,
                    (SELECT status FROM award_notifications an
                     WHERE an.award_id = a.id
                     ORDER BY an.id DESC LIMIT 1) AS notification_status
             FROM awards a
             JOIN students st ON st.id = a.student_id
             JOIN cycle_scholarships cs ON cs.id = a.cycle_scholarship_id
             JOIN scholarships s ON s.id = cs.scholarship_id
             WHERE a.cycle_id = ?
               AND a.status = 'ready_to_notify'
             ORDER BY s.name, st.last_name, st.first_name"
        );
        $stmt->execute([(int) $cycle['id']]);

        $rows = '';
        foreach ($stmt->fetchAll() as $row) {
            $rows .= '<tr>'
                . '<td><input type="checkbox" name="award_ids[]" value="' . (int) $row['id'] . '" aria-label="Select ' . View::e($row['student_name']) . '"></td>'
                . '<td>' . View::e($row['scholarship_name']) . '</td>'
                . '<td><strong>' . View::e($row['student_name']) . '</strong><br><span class="muted">' . View::e($row['email']) . '</span></td>'
                . '<td>' . View::e($row['award_origin'] === 'renewal' ? 'Renewal' : 'New') . '</td>'
                . '<td class="num">' . View::money($row['total_amount']) . '</td>'
                . '<td>' . ($row['notification_status'] ? View::status($row['notification_status']) : 'Not queued') . '</td>'
                . '</tr>';
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="6">No approved awards are currently Ready to Notify.</td></tr>';
        }

        $templateStmt = $this->pdo->query(
            "SELECT * FROM email_templates
             WHERE template_type = 'award_new' AND active = 1
             ORDER BY version_number DESC LIMIT 1"
        );
        $template = $templateStmt->fetch();

        $subject = $template['subject_template'] ?? 'Your {{scholarship_name}} award';
        $html = $template['html_template']
            ?? '<p>Dear {{student_first_name}},</p><p>Congratulations. You have been selected for the {{scholarship_name}} in the amount of {{award_total}}.</p><p>Your award letter is attached. Please use this secure link to access the recipient portal, review your award, and submit your required thank-you letter: {{portal_access_url}}</p>';

        $body = '<div class="page-header"><div><h1>Ready to Notify</h1>'
            . '<p>Nothing is sent automatically. Select one or more approved awards, edit the message, and explicitly queue the notifications.</p></div>'
            . '<a class="button secondary" href="/admin/templates">Templates</a></div>'
            . '<form method="post" action="/notifications/queue">' . View::csrfField()
            . '<section class="card"><h2>Email</h2>'
            . '<label for="subject_template">Subject</label><input id="subject_template" name="subject_template" value="' . View::e($subject) . '" required>'
            . '<label for="email_html_template">HTML message</label><textarea id="email_html_template" name="email_html_template" required>' . View::e($html) . '</textarea>'
            . '<p class="muted">A personalized PDF award letter and recipient-portal link are generated for every selected student.</p></section>'
            . '<section class="card" style="margin-top:1rem"><h2>Approved awards</h2><div class="table-wrap"><table>'
            . '<thead><tr><th>Select</th><th>Scholarship</th><th>Student</th><th>Type</th><th class="num">Amount</th><th>Notification</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table></div>'
            . '<div class="form-actions"><button type="submit">Queue selected notifications</button></div></section></form>';

        return $this->render('Ready to Notify', Flash::render() . $body, $cycle);
    }

    public function queue(): never
    {
        $this->requireAdmin();
        $this->requirePost();
        $cycle = $this->requireCurrentCycle();

        try {
            $awardIds = $_POST['award_ids'] ?? [];
            $subject = trim((string) ($_POST['subject_template'] ?? ''));
            $html = trim((string) ($_POST['email_html_template'] ?? ''));

            if (!is_array($awardIds) || $awardIds === []) {
                throw new RuntimeException('Select at least one award to notify.');
            }

            if ($subject === '' || $html === '') {
                throw new RuntimeException('Email subject and message are required.');
            }

            $ids = array_values(array_unique(array_filter(array_map('intval', $awardIds))));
            $service = new NotificationService(
                $this->pdo,
                new LetterRenderer($this->pdo, new LocalFileStorage())
            );
            $batchId = $service->createBatch(
                (int) $cycle['id'],
                (int) $this->auth->userId(),
                count($ids)
            );

            $queued = 0;
            $errors = [];

            foreach ($ids as $awardId) {
                try {
                    $service->queueAward(
                        $awardId,
                        (int) $this->auth->userId(),
                        $batchId,
                        $subject,
                        $html
                    );
                    $queued++;
                } catch (\Throwable $e) {
                    $errors[] = 'Award ' . $awardId . ': ' . $e->getMessage();
                }
            }

            $this->audit(
                'notification.batch_queued',
                'notification_batch',
                $batchId,
                (int) $cycle['id'],
                null,
                ['requested'=>count($ids),'queued'=>$queued,'errors'=>$errors]
            );

            if ($errors !== []) {
                Flash::error($queued . ' notification(s) queued; ' . count($errors) . ' could not be queued. ' . implode(' ', $errors));
            } else {
                Flash::success($queued . ' notification(s) queued for sending.');
            }
        } catch (\Throwable $e) {
            Flash::error($e->getMessage());
        }

        $this->redirect('/notifications/ready');
    }

    public function history(): string
    {
        $this->requireAdmin();
        $cycle = $this->requireCurrentCycle();

        $stmt = $this->pdo->prepare(
            "SELECT an.*, st.display_name AS student_name, s.name AS scholarship_name
             FROM award_notifications an
             JOIN awards a ON a.id = an.award_id
             JOIN students st ON st.id = a.student_id
             JOIN cycle_scholarships cs ON cs.id = a.cycle_scholarship_id
             JOIN scholarships s ON s.id = cs.scholarship_id
             WHERE a.cycle_id = ?
             ORDER BY an.created_at DESC"
        );
        $stmt->execute([(int) $cycle['id']]);

        $rows = '';
        foreach ($stmt->fetchAll() as $row) {
            $rows .= '<tr><td>' . View::e($row['scholarship_name']) . '</td>'
                . '<td>' . View::e($row['student_name']) . '</td>'
                . '<td>' . View::e($row['recipient_email']) . '</td>'
                . '<td>' . View::status($row['status']) . '</td>'
                . '<td>' . View::e($row['sent_at'] ?: $row['created_at']) . '</td>'
                . '<td>' . View::e($row['error_message'] ?? '') . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="6">No notification history for this cycle.</td></tr>';
        }

        $body = '<div class="page-header"><div><h1>Notification history</h1><p>Immutable per-recipient email and PDF snapshots are retained when notifications are queued.</p></div>'
            . '<a class="button secondary" href="/notifications/ready">Ready to Notify</a></div>'
            . '<div class="table-wrap"><table><thead><tr><th>Scholarship</th><th>Student</th><th>Email</th><th>Status</th><th>Date</th><th>Error</th></tr></thead><tbody>'
            . $rows . '</tbody></table></div>';

        return $this->render('Notification history', Flash::render() . $body, $cycle);
    }
}

<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\Notifications\ThankYouReminderService;
use App\Support\Flash;
use App\Support\View;
use RuntimeException;

final class ThankYouAdminController extends BaseAdminController
{
    public function reminders(): string
    {
        $this->requireAdmin();
        $cycle = $this->requireCurrentCycle();
        $filter = (string) ($_GET['filter'] ?? 'all');

        $where = "a.cycle_id = ? AND a.status IN ('notified','ready_to_notify','approved') AND ts.id IS NULL";
        $params = [(int) $cycle['id']];

        if ($filter === 'past_due') {
            $where .= ' AND ac.thank_you_due_at IS NOT NULL AND ac.thank_you_due_at < NOW()';
        } elseif ($filter === 'due_7') {
            $where .= ' AND ac.thank_you_due_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)';
        }

        $stmt = $this->pdo->prepare(
            "SELECT a.id, st.display_name AS student_name, st.email,
                    s.name AS scholarship_name, s.uica_account_number,
                    ac.thank_you_due_at,
                    (SELECT MAX(sent_at) FROM thank_you_reminder_notifications tr
                     WHERE tr.award_id = a.id AND tr.status = 'sent') AS last_reminder_at
             FROM awards a
             JOIN students st ON st.id = a.student_id
             JOIN cycle_scholarships cs ON cs.id = a.cycle_scholarship_id
             JOIN scholarships s ON s.id = cs.scholarship_id
             JOIN academic_cycles ac ON ac.id = a.cycle_id
             LEFT JOIN thank_you_submissions ts ON ts.award_id = a.id
             WHERE {$where}
             ORDER BY ac.thank_you_due_at, s.name, st.last_name, st.first_name"
        );
        $stmt->execute($params);

        $rows = '';
        foreach ($stmt->fetchAll() as $row) {
            $deadline = $row['thank_you_due_at']
                ? date('M j, Y', strtotime((string) $row['thank_you_due_at']))
                : 'Not set';
            $last = $row['last_reminder_at']
                ? date('M j, Y g:i a', strtotime((string) $row['last_reminder_at']))
                : 'Never';

            $rows .= '<tr>'
                . '<td><input type="checkbox" name="award_ids[]" value="' . (int) $row['id'] . '" aria-label="Select ' . View::e($row['student_name']) . '"></td>'
                . '<td>' . View::e($row['uica_account_number']) . '</td>'
                . '<td>' . View::e($row['scholarship_name']) . '</td>'
                . '<td><strong>' . View::e($row['student_name']) . '</strong><br><span class="muted">' . View::e($row['email']) . '</span></td>'
                . '<td>' . View::e($deadline) . '</td>'
                . '<td>' . View::e($last) . '</td>'
                . '</tr>';
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="6">No recipients match this reminder filter.</td></tr>';
        }

        $template = $this->pdo->query(
            "SELECT * FROM email_templates
             WHERE template_type = 'thank_you_reminder' AND active = 1
             ORDER BY version_number DESC LIMIT 1"
        )->fetch();

        $subject = $template['subject_template'] ?? 'Reminder: thank-you letter for {{scholarship_name}}';
        $bodyHtml = $template['html_template']
            ?? '<p>Dear {{student_first_name}},</p><p>This is a reminder that your thank-you letter for the {{scholarship_name}} is still outstanding. The deadline is {{thank_you_deadline}}.</p>';

        $body = '<div class="page-header"><div><h1>Thank-you reminders</h1>'
            . '<p>Select only recipients who still owe a thank-you letter. Nothing sends until you explicitly queue it.</p></div>'
            . '<a class="button secondary" href="/admin/templates">Email templates</a></div>'
            . '<div class="actions" style="margin-bottom:1rem">'
            . '<a class="button ' . ($filter === 'all' ? '' : 'secondary') . '" href="/admin/thank-you-reminders?filter=all">All outstanding</a>'
            . '<a class="button ' . ($filter === 'due_7' ? '' : 'secondary') . '" href="/admin/thank-you-reminders?filter=due_7">Due within 7 days</a>'
            . '<a class="button ' . ($filter === 'past_due' ? '' : 'secondary') . '" href="/admin/thank-you-reminders?filter=past_due">Past due</a>'
            . '</div>'
            . '<form method="post" action="/admin/thank-you-reminders">' . View::csrfField()
            . '<section class="card"><label for="subject">Subject</label><input id="subject" name="subject_template" value="' . View::e($subject) . '" required>'
            . '<label for="body">HTML message</label><textarea id="body" name="html_template" required>' . View::e($bodyHtml) . '</textarea></section>'
            . '<section class="card" style="margin-top:1rem"><h2>Outstanding recipients</h2><div class="table-wrap"><table>'
            . '<thead><tr><th>Select</th><th>UICA</th><th>Scholarship</th><th>Student</th><th>Deadline</th><th>Last reminder</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table></div>'
            . '<div class="form-actions"><button type="submit">Queue selected reminders</button></div></section></form>';

        return $this->render('Thank-you reminders', Flash::render() . $body, $cycle);
    }

    public function queueReminders(): never
    {
        $this->requireAdmin();
        $this->requirePost();
        $cycle = $this->requireCurrentCycle();

        try {
            $ids = $_POST['award_ids'] ?? [];
            $subject = trim((string) ($_POST['subject_template'] ?? ''));
            $html = trim((string) ($_POST['html_template'] ?? ''));

            if (!is_array($ids) || $ids === [] || $subject === '' || $html === '') {
                throw new RuntimeException('Select recipients and provide the reminder subject/message.');
            }

            $result = (new ThankYouReminderService($this->pdo))->queue(
                (int) $cycle['id'],
                (int) $this->auth->userId(),
                $ids,
                $subject,
                $html
            );

            $this->audit(
                'thank_you.reminders_queued',
                'notification_batch',
                (int) $result['batch_id'],
                (int) $cycle['id'],
                null,
                $result
            );

            Flash::success(
                $result['queued'] . ' reminder(s) queued'
                . ($result['skipped'] ? '; ' . $result['skipped'] . ' skipped.' : '.')
            );
        } catch (\Throwable $e) {
            Flash::error($e->getMessage());
        }

        $this->redirect('/admin/thank-you-reminders');
    }

    public function reviewerAccess(): string
    {
        $this->requireAdmin();
        $cycle = $this->requireCurrentCycle();

        $rows = $this->pdo->prepare(
            "SELECT uua.id, u.display_name, u.email, s.name AS scholarship_name,
                    s.uica_account_number, uua.cycle_id
             FROM user_uica_access uua
             JOIN users u ON u.id = uua.user_id
             JOIN scholarships s ON s.id = uua.scholarship_id
             WHERE uua.active = 1
               AND (uua.cycle_id IS NULL OR uua.cycle_id = ?)
             ORDER BY u.last_name, u.first_name, s.name"
        );
        $rows->execute([(int) $cycle['id']]);

        $table = '';
        foreach ($rows->fetchAll() as $row) {
            $table .= '<tr><td>' . View::e($row['display_name']) . '<br><span class="muted">' . View::e($row['email']) . '</span></td>'
                . '<td>' . View::e($row['uica_account_number']) . '</td>'
                . '<td>' . View::e($row['scholarship_name']) . '</td>'
                . '<td>' . ($row['cycle_id'] ? View::e($cycle['label']) : 'All cycles') . '</td>'
                . '<td><form method="post" action="/admin/thank-you-reviewers/' . (int) $row['id'] . '/remove">'
                . View::csrfField() . '<button class="secondary small" type="submit">Remove</button></form></td></tr>';
        }
        if ($table === '') {
            $table = '<tr><td colspan="5">No scoped thank-you reviewers configured.</td></tr>';
        }

        $users = $this->pdo->query(
            "SELECT id, display_name, email FROM users
             WHERE active = 1 AND person_type = 'staff'
             ORDER BY last_name, first_name"
        )->fetchAll();
        $userOptions = '<option value="">Select staff user</option>';
        foreach ($users as $user) {
            $userOptions .= '<option value="' . (int) $user['id'] . '">' . View::e($user['display_name'] . ' — ' . $user['email']) . '</option>';
        }

        $scholarships = $this->pdo->query(
            "SELECT id, uica_account_number, name FROM scholarships
             WHERE active = 1 ORDER BY uica_account_number, name"
        )->fetchAll();
        $scholarshipOptions = '<option value="">Select UICA account</option>';
        foreach ($scholarships as $s) {
            $scholarshipOptions .= '<option value="' . (int) $s['id'] . '">' . View::e($s['uica_account_number'] . ' — ' . $s['name']) . '</option>';
        }

        $body = '<div class="page-header"><div><h1>Thank-you reviewers</h1>'
            . '<p>Grant any staff user access to review/download thank-you letters for a specific UICA account without broader scholarship administration rights.</p></div></div>'
            . '<section class="card"><h2>Add reviewer access</h2><form method="post" action="/admin/thank-you-reviewers">'
            . View::csrfField()
            . '<div class="form-grid"><div><label for="user_id">Staff user</label><select id="user_id" name="user_id" required>' . $userOptions . '</select></div>'
            . '<div><label for="scholarship_id">UICA account</label><select id="scholarship_id" name="scholarship_id" required>' . $scholarshipOptions . '</select></div>'
            . '<div><label><input type="checkbox" name="all_cycles" value="1"> Apply to all cycles</label></div></div>'
            . '<div class="form-actions"><button type="submit">Grant access</button></div></form></section>'
            . '<section class="card" style="margin-top:1rem"><h2>Current access</h2><div class="table-wrap"><table>'
            . '<thead><tr><th>User</th><th>UICA</th><th>Scholarship</th><th>Scope</th><th>Action</th></tr></thead>'
            . '<tbody>' . $table . '</tbody></table></div></section>';

        return $this->render('Thank-you reviewers', Flash::render() . $body, $cycle);
    }

    public function grantReviewer(): never
    {
        $this->requireAdmin();
        $this->requirePost();
        $cycle = $this->requireCurrentCycle();

        try {
            $userId = (int) ($_POST['user_id'] ?? 0);
            $scholarshipId = (int) ($_POST['scholarship_id'] ?? 0);
            $scopeCycle = isset($_POST['all_cycles']) ? null : (int) $cycle['id'];

            $user = $this->pdo->prepare('SELECT person_type FROM users WHERE id = ? AND active = 1');
            $user->execute([$userId]);
            if ($user->fetchColumn() !== 'staff') {
                throw new RuntimeException('Thank-you review access can only be assigned to a non-student staff user.');
            }

            $stmt = $this->pdo->prepare(
                "INSERT INTO user_uica_access (
                    user_id, scholarship_id, cycle_id, can_download, active
                 ) VALUES (?, ?, ?, 1, 1)
                 ON DUPLICATE KEY UPDATE active = 1, can_download = 1"
            );
            $stmt->execute([$userId, $scholarshipId, $scopeCycle]);

            Flash::success('Thank-you reviewer access granted.');
        } catch (\Throwable $e) {
            Flash::error($e->getMessage());
        }

        $this->redirect('/admin/thank-you-reviewers');
    }

    public function removeReviewer(array $params): never
    {
        $this->requireAdmin();
        $this->requirePost();

        $id = (int) ($params['id'] ?? 0);
        $this->pdo->prepare('UPDATE user_uica_access SET active = 0 WHERE id = ?')->execute([$id]);
        Flash::success('Thank-you reviewer access removed.');
        $this->redirect('/admin/thank-you-reviewers');
    }
}

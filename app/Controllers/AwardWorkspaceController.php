<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthService;
use App\Auth\Gate;
use App\Http\Csrf;
use App\Policies\ProgramScopePolicy;
use App\Services\Imports\SimpleAllocationImportService;
use App\Support\Flash;
use App\Support\View;
use PDO;
use RuntimeException;

final class AwardWorkspaceController
{
    private readonly ProgramScopePolicy $scope;

    public function __construct(private readonly PDO $pdo, private readonly AuthService $auth)
    {
        $this->scope = new ProgramScopePolicy($pdo);
    }

    public function home(): never
    {
        $this->requireStaff();
        header('Location: /awards/available');
        exit;
    }

    /** The annual inventory. Scoped reviewers only see their assigned allocations. */
    public function available(): string
    {
        $this->requireStaff();
        $cycle = $this->cycle();
        $admin = $this->isAdmin();
        $cycleId = (int)$cycle['id'];

        $stmt = $this->pdo->prepare(
            "SELECT ca.id, ca.org_unit_id, ca.recruiting_pool_name,
                    ca.authorized_new_amount, ca.planned_new_award_count, ca.status,
                    s.name AS scholarship_name, s.uica_account_number, ou.name AS unit_name,
                    COALESCE((SELECT SUM(r.recommended_amount) FROM recommendations r
                              WHERE r.cycle_allocation_id = ca.id),0) AS recommended,
                    COALESCE((SELECT SUM(a.total_amount) FROM awards a
                              WHERE a.cycle_allocation_id = ca.id
                                AND a.recommendation_id IS NULL AND a.status <> 'cancelled'),0) AS unlinked_awards
             FROM cycle_allocations ca
             JOIN cycle_scholarships cs ON cs.id = ca.cycle_scholarship_id
             JOIN scholarships s ON s.id = cs.scholarship_id
             JOIN org_units ou ON ou.id = ca.org_unit_id
             WHERE cs.cycle_id = ?
             ORDER BY s.name, ou.name"
        );
        $stmt->execute([$cycleId]);
        $rows = '';
        $available = 0.0;
        $allocated = 0.0;
        $count = 0;

        foreach ($stmt->fetchAll() as $a) {
            if (!$this->canSee((int)$a['org_unit_id'], $cycleId)) {
                continue;
            }
            $count++;
            $balance = max(0, (float)$a['authorized_new_amount']
                - (float)$a['recommended'] - (float)$a['unlinked_awards']);
            $allocated += (float)$a['authorized_new_amount'];
            $available += $balance;
            $unit = $a['unit_name'] . ($a['recruiting_pool_name'] ? ' · ' . $a['recruiting_pool_name'] : '');
            $account = $admin ? '<br><span class="muted">UICA ' . View::e($a['uica_account_number']) . '</span>' : '';
            $rows .= '<tr><td><strong>' . View::e($a['scholarship_name']) . '</strong>' . $account . '</td>'
                . '<td>' . View::e($unit) . '</td>'
                . '<td class="num">' . View::money($a['authorized_new_amount']) . '</td>'
                . '<td class="num">' . View::money((float)$a['recommended'] + (float)$a['unlinked_awards']) . '</td>'
                . '<td class="num"><strong>' . View::money($balance) . '</strong></td>'
                . '<td>' . View::e($a['planned_new_award_count'] ?? '—') . '</td>'
                . '<td><a class="button secondary small" href="/awards/recommendations">Recipients</a></td></tr>';
        }

        // Funds with no allocation remain visible to the Dean's Office.
        if ($admin) {
            $unassigned = $this->pdo->prepare(
                "SELECT s.name, s.uica_account_number, cs.total_authorized_amount,
                        COALESCE(SUM(ca.authorized_new_amount),0) AS allocated_amount
                 FROM cycle_scholarships cs
                 JOIN scholarships s ON s.id = cs.scholarship_id
                 LEFT JOIN cycle_allocations ca ON ca.cycle_scholarship_id = cs.id
                 WHERE cs.cycle_id = ?
                 GROUP BY cs.id, s.name, s.uica_account_number, cs.total_authorized_amount
                 HAVING total_authorized_amount > allocated_amount + 0.005
                 ORDER BY s.name"
            );
            $unassigned->execute([$cycleId]);
            foreach ($unassigned->fetchAll() as $a) {
                $count++;
                $remainder = (float)$a['total_authorized_amount'] - (float)$a['allocated_amount'];
                $available += $remainder;
                $rows .= '<tr><td><strong>' . View::e($a['name']) . '</strong><br>'
                    . '<span class="muted">UICA ' . View::e($a['uica_account_number']) . '</span></td>'
                    . '<td>Not assigned</td><td class="num">' . View::money($remainder) . '</td>'
                    . '<td class="num">—</td><td class="num"><strong>' . View::money($remainder) . '</strong></td>'
                    . '<td>—</td><td><a class="button secondary small" href="/admin/allocations">Assign</a></td></tr>';
            }
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="7">No scholarships are assigned to you for this cycle.</td></tr>';
        }

        $actions = $admin
            ? '<a class="button secondary" href="/admin/award-authority">Import FY27 workbook</a>'
                . '<a class="button secondary" href="/admin/allocations">Edit allocations</a>'
            : '<a class="button secondary" href="/review">Program review</a>';

        $import = '';
        if ($admin) {
            $import = '<details class="card" style="margin-top:1rem"><summary><strong>Import standardized allocations</strong></summary>'
                . '<p>Upload a CSV or Excel file to establish fund amounts and assignments for the current cycle. '
                . 'A complete upload is validated before any changes are saved. '
                . 'Existing recommendations and awards are preserved.</p>'
                . '<p><a href="/awards/allocations/template">Download blank allocation template</a></p>'
                . '<form method="post" action="/awards/allocations/import" enctype="multipart/form-data">'
                . View::csrfField()
                . '<label for="allocation_file">Allocation file</label>'
                . '<input id="allocation_file" name="allocation_file" type="file" accept=".csv,.tsv,.xlsx,.xls" required>'
                . '<div class="form-actions"><button type="submit">Import allocations</button></div>'
                . '</form></details>';
        }

        $body = '<div class="page-header"><div><h1>Available Awards</h1>'
            . '<p>What funds and award allocations are available for ' . View::e($cycle['label']) . '?</p></div>'
            . '<div class="actions">' . $actions . '</div></div>'
            . '<div class="metric-grid">'
            . '<section class="metric-card"><div><span class="metric-label">Assigned award pools</span>'
            . '<strong class="metric-value">' . $count . '</strong></div></section>'
            . '<section class="metric-card"><div><span class="metric-label">Assigned total</span>'
            . '<strong class="metric-value">' . View::money($allocated) . '</strong></div></section>'
            . '<section class="metric-card"><div><span class="metric-label">Still available</span>'
            . '<strong class="metric-value">' . View::money($available) . '</strong></div></section></div>'
            . '<section class="card" style="margin-top:1rem"><div class="module-header"><div><h2>Scholarship inventory</h2>'
            . '<p>Recommendations reserve funds until the Dean’s Office makes the final decision.</p></div></div>'
            . '<div class="table-wrap"><table><thead><tr><th>Scholarship</th><th>Assigned to</th>'
            . '<th class="num">Authorized</th><th class="num">Committed</th><th class="num">Available</th>'
            . '<th>Planned awards</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table></div></section>'
            . $import;

        return $this->page('Available Awards', $body, $cycle);
    }

    /** Nominations from applicants and recruitment students share one queue. */
    public function recommendations(): string
    {
        $this->requireStaff();
        $cycle = $this->cycle();
        $admin = $this->isAdmin();
        $cycleId = (int)$cycle['id'];

        $stmt = $this->pdo->prepare(
            "SELECT r.id, r.is_recruitment, r.recommended_amount, r.eligibility_status_at_submission,
                    r.cycle_allocation_id, st.display_name AS student_name, st.university_id,
                    s.name AS scholarship_name, ou.name AS unit_name, ca.org_unit_id, ca.recruiting_pool_name,
                    a.id AS award_id, a.status AS award_status
             FROM recommendations r
             JOIN students st ON st.id = r.student_id
             JOIN cycle_allocations ca ON ca.id = r.cycle_allocation_id
             JOIN org_units ou ON ou.id = ca.org_unit_id
             JOIN cycle_scholarships cs ON cs.id = ca.cycle_scholarship_id
             JOIN scholarships s ON s.id = cs.scholarship_id
             LEFT JOIN awards a ON a.recommendation_id = r.id
             WHERE cs.cycle_id = ?
             ORDER BY s.name, st.last_name, st.first_name"
        );
        $stmt->execute([$cycleId]);
        $rows = '';
        $count = 0;
        foreach ($stmt->fetchAll() as $r) {
            if (!$this->canSee((int)$r['org_unit_id'], $cycleId)) {
                continue;
            }
            $count++;
            $source = $r['is_recruitment'] ? 'Recruitment' : 'Applicant';
            $unit = $r['unit_name'] . ($r['recruiting_pool_name'] ? ' · ' . $r['recruiting_pool_name'] : '');
            $action = $r['award_id']
                ? '<a href="/awards/tracker">View award</a>'
                : ($admin
                    ? '<form method="post" action="/dean/recommendations/' . (int)$r['id'] . '/award">'
                        . View::csrfField() . '<button class="small" type="submit">Start verification</button></form>'
                    : '<span class="muted">Awaiting Dean’s Office</span>');
            $rows .= '<tr><td><strong>' . View::e($r['student_name']) . '</strong><br>'
                . '<span class="muted">' . View::e($r['university_id']) . '</span></td>'
                . '<td>' . View::e($r['scholarship_name']) . '<br><span class="muted">' . View::e($unit) . '</span></td>'
                . '<td>' . $source . '</td>'
                . '<td class="num">' . View::money($r['recommended_amount']) . '</td>'
                . '<td>' . View::status($r['eligibility_status_at_submission'] ?: 'insufficient_information') . '</td>'
                . '<td>' . ($r['award_id'] ? View::status($r['award_status']) : 'Recommended') . '</td>'
                . '<td>' . $action . '</td></tr>';
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="7">No recipients have been recommended yet.</td></tr>';
        }

        $nominationOptions = '';
        $allocations = $this->pdo->prepare(
            "SELECT ca.id, ca.org_unit_id, ca.status, ca.primary_reviewer_user_id, s.name AS scholarship_name,
                    ou.name AS unit_name FROM cycle_allocations ca
             JOIN cycle_scholarships cs ON cs.id = ca.cycle_scholarship_id
             JOIN scholarships s ON s.id = cs.scholarship_id
             JOIN org_units ou ON ou.id = ca.org_unit_id
             WHERE cs.cycle_id = ? ORDER BY s.name, ou.name"
        );
        $allocations->execute([$cycleId]);
        foreach ($allocations->fetchAll() as $a) {
            if ($this->canNominate($a, $cycleId)) {
                $nominationOptions .= '<option value="' . (int)$a['id'] . '">'
                    . View::e($a['scholarship_name'] . ' — ' . $a['unit_name']) . '</option>';
            }
        }

        $nomination = $nominationOptions === '' ? '' :
            '<details class="card" style="margin-top:1rem"><summary><strong>Nominate a recruitment student who did not apply</strong></summary>'
            . '<p>Use this only for recruitment awards. Student ID and email must be verified before approval.</p>'
            . '<form method="post" action="/awards/recommendations/recruitment">' . View::csrfField()
            . '<div class="form-grid">'
            . '<div><label for="allocation_id">Scholarship allocation</label><select id="allocation_id" name="allocation_id" required>'
            . $nominationOptions . '</select></div>'
            . '<div><label for="university_id">University student ID</label>'
            . '<input id="university_id" name="university_id" maxlength="50" required></div>'
            . '<div><label for="first_name">First name</label><input id="first_name" name="first_name" maxlength="120" required></div>'
            . '<div><label for="last_name">Last name</label><input id="last_name" name="last_name" maxlength="120" required></div>'
            . '<div><label for="email">University email</label><input id="email" name="email" type="email" maxlength="320" required></div>'
            . '<div><label for="amount">Recommended amount ($)</label>'
            . '<input id="amount" name="amount" type="number" step="0.01" min="0.01" required></div>'
            . '</div><div class="form-actions"><button type="submit">Nominate student</button></div></form></details>';

        $links = $admin
            ? '<a class="button secondary" href="/admin/applicants">Upload applicants</a>'
                . '<a class="button secondary" href="/dean/verification">Verify eligibility</a>'
            : '<a class="button secondary" href="/review">Select from applicants</a>';

        $body = '<div class="page-header"><div><h1>Awards & Recipients</h1>'
            . '<p>Who has been recommended? Applicant selections and recruitment nominations appear together.</p></div>'
            . '<div class="actions">' . $links . '</div></div>'
            . '<section class="card"><div class="module-header"><div><h2>Recommendations</h2>'
            . '<p>' . $count . ' recommendation(s) in the current cycle.</p></div></div>'
            . '<div class="table-wrap"><table><thead><tr><th>Student</th><th>Scholarship / unit</th>'
            . '<th>Source</th><th class="num">Amount</th><th>Eligibility</th><th>Stage</th><th>Next step</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table></div></section>'
            . $nomination;
        return $this->page('Awards & Recipients', $body, $cycle);
    }

    public function nominateRecruitment(): never
    {
        $this->requireStaff();
        Csrf::assertValid($_POST['_csrf'] ?? null);
        $cycle = $this->cycle();
        $cycleId = (int)$cycle['id'];
        try {
            $id = (int)($_POST['allocation_id'] ?? 0);
            $uid = trim((string)($_POST['university_id'] ?? ''));
            $first = trim((string)($_POST['first_name'] ?? ''));
            $last = trim((string)($_POST['last_name'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $amount = trim((string)($_POST['amount'] ?? ''));
            if ($uid === '' || strlen($uid) > 50 || $first === '' || $last === ''
                || strlen($first) > 120 || strlen($last) > 120
                || !filter_var($email, FILTER_VALIDATE_EMAIL)
                || !preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', $amount) || (float)$amount <= 0) {
                throw new RuntimeException('Enter a valid student ID, name, email, and award amount.');
            }
            $this->pdo->beginTransaction();
            $stmt = $this->pdo->prepare(
                'SELECT ca.*, cs.cycle_id FROM cycle_allocations ca
                 JOIN cycle_scholarships cs ON cs.id = ca.cycle_scholarship_id
                 WHERE ca.id = ? AND cs.cycle_id = ? FOR UPDATE'
            );
            $stmt->execute([$id, $cycleId]);
            $allocation = $stmt->fetch();
            if (!$allocation || !$this->canNominate($allocation, $cycleId)) {
                throw new RuntimeException('You do not have permission to nominate a student for this allocation.');
            }
            $sum = $this->pdo->prepare(
                'SELECT COALESCE(SUM(recommended_amount),0) FROM recommendations WHERE cycle_allocation_id = ?'
            );
            $sum->execute([$id]);
            $committed = (float)$sum->fetchColumn();
            $other = $this->pdo->prepare(
                "SELECT COALESCE(SUM(total_amount),0) FROM awards
                 WHERE cycle_allocation_id = ? AND recommendation_id IS NULL AND status <> 'cancelled'"
            );
            $other->execute([$id]);
            $committed += (float)$other->fetchColumn();
            if ($committed + (float)$amount > (float)$allocation['authorized_new_amount'] + .005) {
                throw new RuntimeException('The nomination exceeds the remaining allocation.');
            }
            $student = $this->pdo->prepare('SELECT id, email FROM students WHERE university_id = ? FOR UPDATE');
            $student->execute([$uid]);
            $found = $student->fetch();
            if ($found) {
                if (strcasecmp((string)$found['email'], $email) !== 0) {
                    throw new RuntimeException('The student ID already exists with a different email. Verify it before nominating.');
                }
                $studentId = (int)$found['id'];
            } else {
                $this->pdo->prepare(
                    'INSERT INTO students (public_id, university_id, first_name, last_name, display_name, email)
                     VALUES (UUID(), ?, ?, ?, ?, ?)'
                )->execute([$uid, $first, $last, $first . ' ' . $last, $email]);
                $studentId = (int)$this->pdo->lastInsertId();
            }
            $dupe = $this->pdo->prepare(
                'SELECT id FROM recommendations WHERE cycle_allocation_id = ? AND student_id = ?'
            );
            $dupe->execute([$id, $studentId]);
            if ($dupe->fetchColumn()) {
                throw new RuntimeException('This student is already recommended for this allocation.');
            }
            $rank = $this->pdo->prepare(
                'SELECT COALESCE(MAX(rank_position),0) + 1 FROM recommendations WHERE cycle_allocation_id = ?'
            );
            $rank->execute([$id]);
            $position = (int)$rank->fetchColumn();
            $this->pdo->prepare(
                "INSERT INTO recommendations
                 (cycle_allocation_id, student_id, is_recruitment, rank_position, recommended_amount,
                  eligibility_status_at_submission, created_by_user_id)
                 VALUES (?, ?, 1, ?, ?, 'insufficient_information', ?)"
            )->execute([$id, $studentId, $position, $amount, $this->auth->userId()]);
            $this->audit('recruitment.nominated', 'recommendation', (int)$this->pdo->lastInsertId(), $cycleId);
            $this->pdo->commit();
            Flash::success('Recruitment nomination added. Student eligibility still requires verification and Dean’s Office approval.');
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            Flash::error($e->getMessage());
        }
        header('Location: /awards/recommendations');
        exit;
    }

    /** One row per recipient award; MAUI export is not proof of MAUI processing. */
    public function tracker(): string
    {
        $this->requireStaff();
        $cycle = $this->cycle();
        $admin = $this->isAdmin();
        $stmt = $this->pdo->prepare(
            "SELECT a.id, a.total_amount, a.status, a.enrollment_status,
                    a.maui_export_batch_id, a.maui_exported_at, a.cycle_allocation_id,
                    ca.org_unit_id, ca.recruiting_pool_name, ou.name AS unit_name,
                    st.display_name AS student_name, s.name AS scholarship_name,
                    (SELECT status FROM award_notifications n WHERE n.award_id = a.id
                     ORDER BY n.id DESC LIMIT 1) AS mail_status,
                    (SELECT submitted_at FROM thank_you_submissions t WHERE t.award_id = a.id
                     ORDER BY t.id DESC LIMIT 1) AS thank_you_at
             FROM awards a
             JOIN students st ON st.id = a.student_id
             JOIN cycle_scholarships cs ON cs.id = a.cycle_scholarship_id
             JOIN scholarships s ON s.id = cs.scholarship_id
             LEFT JOIN cycle_allocations ca ON ca.id = a.cycle_allocation_id
             LEFT JOIN org_units ou ON ou.id = ca.org_unit_id
             WHERE a.cycle_id = ? AND a.status <> 'cancelled'
             ORDER BY a.created_at DESC"
        );
        $stmt->execute([(int)$cycle['id']]);
        $rows = '';
        $inProcess = 0;
        $complete = 0;
        foreach ($stmt->fetchAll() as $a) {
            if (!$admin && (!$a['org_unit_id'] || !$this->canSee((int)$a['org_unit_id'], (int)$cycle['id']))) {
                continue;
            }
            $finished = $a['maui_exported_at'] && $a['mail_status'] === 'sent' && $a['thank_you_at'];
            $finished ? $complete++ : $inProcess++;
            $stage = $finished ? 'Complete'
                : ($a['thank_you_at'] ? 'Thank-you received'
                : ($a['mail_status'] === 'sent' ? 'Letter sent'
                : ($a['mail_status'] === 'queued' ? 'Letter queued'
                : ($a['maui_exported_at'] ? 'Exported to MAUI file'
                : (in_array($a['status'], ['approved','ready_to_notify','notified'], true)
                    ? 'Approved / not exported' : 'Verifying eligibility')))));
            $rows .= '<tr><td><strong>' . View::e($a['student_name']) . '</strong></td>'
                . '<td>' . View::e($a['scholarship_name']) . '<br><span class="muted">'
                . View::e($a['unit_name'] ?: 'Dean’s Office') . '</span></td>'
                . '<td class="num">' . View::money($a['total_amount']) . '</td>'
                . '<td>' . View::status($a['enrollment_status']) . '</td>'
                . '<td>' . ($a['maui_exported_at'] ? 'File exported' : 'Not exported') . '</td>'
                . '<td>' . ($a['mail_status'] === 'sent' ? 'Sent' : View::e($a['mail_status'] ?: 'Not sent')) . '</td>'
                . '<td>' . ($a['thank_you_at'] ? 'Received' : 'Pending') . '</td>'
                . '<td>' . View::e($stage) . '</td></tr>';
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="8">No recipient awards in this cycle yet.</td></tr>';
        }
        $actions = $admin
            ? '<a class="button secondary" href="/dean/verification">Verify and approve</a>'
                . '<a class="button secondary" href="/notifications/ready">Generate letters</a>'
                . '<a class="button secondary" href="/admin/thank-yous">Thank-you notes</a>'
            : '<a class="button secondary" href="/thank-yous/review">Thank-you review</a>';
        $export = $admin
            ? '<section class="card" style="margin-top:1rem"><h2>MAUI export</h2>'
                . '<p>Download a preparation CSV of approved, enrollment-verified awards and term distributions. '
                . '<strong>The file layout still needs to be checked against the University’s actual MAUI upload template.</strong> '
                . 'Generating a file records the export; it does not confirm successful processing in MAUI.</p>'
                . '<form method="post" action="/awards/maui-export">' . View::csrfField()
                . '<button type="submit">Export new approved awards</button></form></section>'
            : '';

        $body = '<div class="page-header"><div><h1>Award Tracker</h1>'
            . '<p>Where is each award in the processing, notification, and thank-you workflow?</p></div>'
            . '<div class="actions">' . $actions . '</div></div>'
            . '<div class="metric-grid">'
            . '<section class="metric-card"><div><span class="metric-label">In process</span>'
            . '<strong class="metric-value">' . $inProcess . '</strong></div></section>'
            . '<section class="metric-card"><div><span class="metric-label">Complete</span>'
            . '<strong class="metric-value">' . $complete . '</strong></div></section></div>'
            . '<section class="card" style="margin-top:1rem"><h2>Recipient awards</h2>'
            . '<div class="table-wrap"><table><thead><tr><th>Recipient</th><th>Scholarship</th>'
            . '<th class="num">Amount</th><th>Eligibility</th><th>MAUI file</th><th>Letter</th>'
            . '<th>Thank-you</th><th>Stage</th></tr></thead><tbody>' . $rows . '</tbody></table></div></section>'
            . $export;

        return $this->page('Award Tracker', $body, $cycle);
    }

    public function allocationTemplate(): never
    {
        $this->requireAdmin();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="scholarship-allocations-template.csv"');
        $fp = fopen('php://output', 'wb');
        fputcsv($fp, SimpleAllocationImportService::HEADERS);
        fclose($fp);
        exit;
    }

    public function importAllocations(): never
    {
        $this->requireAdmin();
        Csrf::assertValid($_POST['_csrf'] ?? null);
        try {
            $file = $_FILES['allocation_file'] ?? null;
            if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Choose a CSV, TSV, or Excel allocation file.');
            }
            $result = (new SimpleAllocationImportService($this->pdo))->import(
                (string)$file['tmp_name'], (string)$file['name'], $this->cycle()
            );
            $this->audit('allocations.standard_import', 'academic_cycle', (int)$this->cycle()['id'], (int)$this->cycle()['id']);
            Flash::success($result['rows'] . ' allocation rows imported (' . $result['created'] . ' new, '
                . $result['updated'] . ' updated).');
        } catch (\Throwable $e) {
            Flash::error($e->getMessage());
        }
        header('Location: /awards/available');
        exit;
    }

    public function exportMaui(): never
    {
        $this->requireAdmin();
        Csrf::assertValid($_POST['_csrf'] ?? null);
        $cycle = $this->cycle();
        try {
            $this->pdo->beginTransaction();
            $stmt = $this->pdo->prepare(
                "SELECT a.id, a.total_amount, st.university_id,
                        s.uica_account_number, s.name AS scholarship_name
                 FROM awards a
                 JOIN students st ON st.id = a.student_id
                 JOIN cycle_scholarships cs ON cs.id = a.cycle_scholarship_id
                 JOIN scholarships s ON s.id = cs.scholarship_id
                 WHERE a.cycle_id = ? AND a.status IN ('approved','ready_to_notify','notified')
                   AND a.enrollment_status = 'verified_enrolled'
                   AND a.maui_export_batch_id IS NULL
                 ORDER BY a.id FOR UPDATE"
            );
            $stmt->execute([(int)$cycle['id']]);
            $awards = $stmt->fetchAll();
            if ($awards === []) {
                throw new RuntimeException('There are no newly approved, verified awards to export.');
            }
            $distributions = $this->pdo->prepare(
                'SELECT d.amount, t.term_key FROM award_distributions d
                 JOIN academic_terms t ON t.id = d.academic_term_id
                 WHERE d.award_id = ? AND t.cycle_id = ? ORDER BY t.season'
            );
            $fp = fopen('php://temp', 'w+');
            fputcsv($fp, ['University ID', 'UICA Account Number', 'Academic Term', 'Amount', 'Award ID', 'Scholarship']);
            $sum = 0.0;
            $lineCount = 0;
            $ids = [];
            foreach ($awards as $a) {
                $distributions->execute([(int)$a['id'], (int)$cycle['id']]);
                $termRows = $distributions->fetchAll();
                $termSum = array_sum(array_map(static fn(array $d): float => (float)$d['amount'], $termRows));
                if ($termRows === [] || abs($termSum - (float)$a['total_amount']) > .005) {
                    throw new RuntimeException('Award ' . $a['id'] . ': term distribution amounts do not match the approved award.');
                }
                $ids[] = (int)$a['id'];
                $sum += (float)$a['total_amount'];
                foreach ($termRows as $d) {
                    fputcsv($fp, [
                        $this->csvSafe((string)$a['university_id']),
                        $this->csvSafe((string)$a['uica_account_number']),
                        $this->csvSafe((string)$d['term_key']),
                        number_format((float)$d['amount'], 2, '.', ''),
                        (int)$a['id'],
                        $this->csvSafe((string)$a['scholarship_name']),
                    ]);
                    $lineCount++;
                }
            }
            $this->pdo->prepare(
                'INSERT INTO maui_export_batches
                 (cycle_id, exported_by_user_id, award_count, distribution_count, total_amount)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([(int)$cycle['id'], $this->auth->userId(), count($ids), $lineCount, $sum]);
            $batch = (int)$this->pdo->lastInsertId();
            $update = $this->pdo->prepare(
                'UPDATE awards SET maui_export_batch_id = ?, maui_exported_at = NOW()
                 WHERE id = ? AND maui_export_batch_id IS NULL'
            );
            foreach ($ids as $id) {
                $update->execute([$batch, $id]);
                if ($update->rowCount() !== 1) {
                    throw new RuntimeException('Another export updated these awards. Please retry.');
                }
            }
            $this->audit('awards.maui_file_generated', 'maui_export_batch', $batch, (int)$cycle['id']);
            $this->pdo->commit();
            rewind($fp);
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="MAUI-PREPARATION-' .
                preg_replace('/[^a-zA-Z0-9-]/', '-', (string)$cycle['label']) . '-batch-' . $batch . '.csv"');
            header('Cache-Control: private, no-store');
            fpassthru($fp);
            fclose($fp);
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            Flash::error($e->getMessage());
            header('Location: /awards/tracker');
        }
        exit;
    }

    private function csvSafe(string $value): string
    {
        return preg_match('/^[\s]*[=+\-@]/u', $value) ? "'" . $value : $value;
    }

    private function canSee(int $unitId, int $cycleId): bool
    {
        return $this->scope->canViewOrgUnit((int)$this->auth->userId(), $this->auth->staffRole(), $unitId, $cycleId);
    }

    private function canNominate(array $allocation, int $cycleId): bool
    {
        if (!$this->canSee((int)$allocation['org_unit_id'], $cycleId)
            || in_array($allocation['status'], ['submitted','closed'], true)) {
            return false;
        }
        if ($this->isAdmin()) {
            return true;
        }
        if ($this->auth->staffRole() !== 'program_coordinator') {
            return false;
        }
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM cycle_review_units
             WHERE cycle_id = ? AND org_unit_id = ? AND primary_reviewer_user_id = ?
             AND status IN ("not_started","draft","reopened") LIMIT 1'
        );
        $stmt->execute([$cycleId, (int)$allocation['org_unit_id'], $this->auth->userId()]);
        return (bool)$stmt->fetchColumn();
    }

    private function isAdmin(): bool
    {
        return Gate::isAdmin($this->auth->staffRole());
    }

    private function requireAdmin(): void
    {
        $this->requireStaff();
        if (!$this->isAdmin()) {
            http_response_code(403);
            throw new RuntimeException('This action is restricted to the Dean’s Office.');
        }
    }

    private function requireStaff(): void
    {
        if (!$this->auth->check() || $this->auth->personType() !== 'staff') {
            header('Location: /login');
            exit;
        }
    }

    private function cycle(): array
    {
        $cycle = $this->pdo->query(
            'SELECT * FROM academic_cycles WHERE is_current = 1 LIMIT 1'
        )->fetch();
        if (!$cycle) {
            throw new RuntimeException('No current academic cycle is configured. Set up a cycle first.');
        }
        return $cycle;
    }

    private function page(string $title, string $body, array $cycle): string
    {
        return View::layout($title, Flash::render() . $body,
            $this->auth->displayName(), $this->auth->staffRole(), $cycle['label']);
    }

    private function audit(string $event, string $entity, int $id, int $cycleId): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO audit_log (actor_user_id, event_type, entity_type, entity_id, cycle_id)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$this->auth->userId(), $event, $entity, $id, $cycleId]);
    }
}

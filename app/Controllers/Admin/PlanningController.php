<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\Allocation\AllocationService;
use App\Services\Renewal\RenewalService;
use App\Support\Flash;
use App\Support\View;
use RuntimeException;

final class PlanningController extends BaseAdminController
{
    public function planning(): string
    {
        $this->requireAdmin();
        $cycle = $this->requireCurrentCycle();

        $stmt = $this->pdo->prepare(
            "SELECT
                cs.id,
                cs.total_authorized_amount,
                cs.planned_new_award_count,
                cs.suggested_new_award_amount,
                cs.award_category,
                cs.award_plan_text,
                cs.source_department_area,
                cs.source_student_level,
                cs.authority_note,
                cs.planning_status,
                s.uica_account_number,
                s.mfk,
                s.name,
                sar.renewable,
                COALESCE((
                    SELECT SUM(rc.proposed_current_amount)
                    FROM renewal_candidates rc
                    WHERE rc.cycle_scholarship_id = cs.id
                      AND rc.status = 'confirmed'
                ), 0) AS confirmed_renewals,
                COALESCE((
                    SELECT SUM(ca.authorized_new_amount)
                    FROM cycle_allocations ca
                    WHERE ca.cycle_scholarship_id = cs.id
                ), 0) AS allocated_new
             FROM cycle_scholarships cs
             JOIN scholarships s ON s.id = cs.scholarship_id
             LEFT JOIN scholarship_award_rules sar ON sar.intent_version_id = cs.intent_version_id
             WHERE cs.cycle_id = ?
             ORDER BY s.name"
        );
        $stmt->execute([(int) $cycle['id']]);

        $rows = '';
        foreach ($stmt->fetchAll() as $row) {
            $available = max(0, (float) $row['total_authorized_amount'] - (float) $row['confirmed_renewals']);
            $remaining = max(0, $available - (float) $row['allocated_new']);

            $guidance = $row['award_plan_text'] ?: '—';
            $sourceMeta = implode(' · ', array_values(array_filter([
                $row['source_department_area'] ?? null,
                $row['source_student_level'] ?? null,
            ], static fn($value): bool => trim((string)$value) !== '')));

            $rows .= '<tr>'
                . '<td><strong>' . View::e($row['name']) . '</strong><br><span class="muted">UICA '
                . View::e($row['uica_account_number']) . ($row['mfk'] ? ' · MFK ' . View::e($row['mfk']) : '') . '</span></td>'
                . '<td>' . View::e($guidance)
                . ($sourceMeta !== '' ? '<br><span class="muted">' . View::e($sourceMeta) . '</span>' : '')
                . ($row['authority_note'] ? '<br><span class="muted">Authority note: ' . View::e($row['authority_note']) . '</span>' : '')
                . '</td>'
                . '<td class="num">' . View::money($row['total_authorized_amount']) . '</td>'
                . '<td class="num">' . View::money($row['confirmed_renewals']) . '</td>'
                . '<td class="num">' . View::money($available) . '</td>'
                . '<td class="num">' . View::money($row['allocated_new']) . '</td>'
                . '<td class="num">' . View::money($remaining) . '</td>'
                . '<td>' . View::status($row['planning_status']) . '</td>'
                . '<td><a class="button secondary small" href="/admin/planning/' . (int) $row['id'] . '">Edit</a></td>'
                . '</tr>';
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="9">No scholarships have been added to this cycle yet.</td></tr>';
        }

        $body = <<<HTML
<div class="page-header">
    <div>
        <h1>Annual scholarship planning</h1>
        <p>Set the Dean's Office annual award budget, account for renewals, and track dollars available for new awards.</p>
    </div>
    <div class="actions"><a class="button" href="/admin/award-authority">Import award authority</a></div>
</div>
<div class="table-wrap">
<table>
    <thead>
        <tr>
            <th>Scholarship</th>
            <th>Award guidance</th>
            <th class="num">Annual total</th>
            <th class="num">Renewals</th>
            <th class="num">New available</th>
            <th class="num">Allocated</th>
            <th class="num">Unallocated</th>
            <th>Status</th>
            <th>Action</th>
        </tr>
    </thead>
    <tbody>{$rows}</tbody>
</table>
</div>
HTML;

        return $this->render('Annual planning', $body, $cycle);
    }

    public function editPlan(array $params): string
    {
        $this->requireAdmin();
        $cycle = $this->requireCurrentCycle();
        $id = (int) ($params['id'] ?? 0);

        $stmt = $this->pdo->prepare(
            "SELECT cs.*, s.name, s.uica_account_number, siv.original_intent_text,
                    sar.amount_mode, sar.renewable, sar.manual_amount_rule_text
             FROM cycle_scholarships cs
             JOIN scholarships s ON s.id = cs.scholarship_id
             JOIN scholarship_intent_versions siv ON siv.id = cs.intent_version_id
             LEFT JOIN scholarship_award_rules sar ON sar.intent_version_id = cs.intent_version_id
             WHERE cs.id = ? AND cs.cycle_id = ?"
        );
        $stmt->execute([$id, (int) $cycle['id']]);
        $plan = $stmt->fetch();

        if (!$plan) {
            http_response_code(404);
            return $this->render('Plan not found', '<div class="error">Scholarship plan not found.</div>', $cycle);
        }

        $summary = (new AllocationService($this->pdo))->budgetSummary($id);
        $ruleText = $plan['manual_amount_rule_text']
            ? '<p><strong>Amount rule:</strong> ' . View::e($plan['manual_amount_rule_text']) . '</p>'
            : '';

        $guidance = '';
        if ($plan['award_plan_text']) {
            $guidance .= '<p><strong>Award guidance:</strong> ' . View::e($plan['award_plan_text']) . '</p>';
        }
        $sourceMeta = implode(' · ', array_values(array_filter([
            $plan['source_department_area'] ?? null,
            $plan['source_student_level'] ?? null,
        ], static fn($value): bool => trim((string)$value) !== '')));
        if ($sourceMeta !== '') {
            $guidance .= '<p><strong>Workbook routing:</strong> ' . View::e($sourceMeta) . '</p>';
        }
        if ($plan['authority_note']) {
            $guidance .= '<div class="notice"><strong>Authority note:</strong> ' . View::e($plan['authority_note']) . '</div>';
        }

        $snapshotStmt = $this->pdo->prepare(
            'SELECT fs.*, ai.filename, ai.created_at AS imported_at
             FROM cycle_fund_financial_snapshots fs
             JOIN annual_authority_imports ai ON ai.id = fs.annual_authority_import_id
             WHERE fs.cycle_scholarship_id = ?
             ORDER BY fs.id DESC LIMIT 1'
        );
        $snapshotStmt->execute([$id]);
        $snapshot = $snapshotStmt->fetch() ?: null;

        $snapshotHtml = '';
        if ($snapshot) {
            $moneyOrDash = static fn(mixed $value): string => $value === null ? '—' : View::money($value);
            $donorReport = $snapshot['donor_report_required'] === null
                ? '—'
                : ((int)$snapshot['donor_report_required'] === 1 ? 'Yes' : 'No');
            $contact = implode(' · ', array_values(array_filter([
                $snapshot['donor_report_recipient'] ?? null,
                $snapshot['donor_report_contact'] ?? null,
            ], static fn($value): bool => trim((string)$value) !== '')));

            $snapshotHtml = '<section class="card" style="margin-top:1rem"><h2>Administrator fund snapshot</h2>'
                . '<p class="muted">Financial and donor-reporting details from the imported workbook. These fields are not exposed to faculty reviewers.</p>'
                . '<div class="grid">'
                . '<section class="stat"><span>Account balance</span><strong>' . $moneyOrDash($snapshot['account_balance']) . '</strong></section>'
                . '<section class="stat"><span>Next payout projection</span><strong>' . $moneyOrDash($snapshot['next_fy_payout_projection']) . '</strong></section>'
                . '<section class="stat"><span>Spendable cash</span><strong>' . $moneyOrDash($snapshot['spendable_cash']) . '</strong></section>'
                . '<section class="stat"><span>Endowed investment</span><strong>' . $moneyOrDash($snapshot['endowed_investment']) . '</strong></section>'
                . '</div>'
                . '<p><strong>Donor report required:</strong> ' . View::e($donorReport) . '</p>'
                . ($contact !== '' ? '<p><strong>Donor report contact:</strong> ' . View::e($contact) . '</p>' : '')
                . ($snapshot['uica_comments'] ? '<p><strong>UICA comments:</strong> ' . nl2br(View::e($snapshot['uica_comments'])) . '</p>' : '')
                . '<p class="muted">Source: ' . View::e($snapshot['filename']) . ' · '
                . View::e($snapshot['source_sheet']) . ' row ' . (int)$snapshot['source_row_number'] . '</p>'
                . '</section>';
        }

        $body = '<div class="page-header"><div><h1>' . View::e($plan['name']) . '</h1>'
            . '<p>UICA ' . View::e($plan['uica_account_number']) . '</p></div>'
            . '<a class="button secondary" href="/admin/planning">Back to planning</a></div>'
            . '<div class="grid">'
            . '<section class="stat"><span>Confirmed renewals</span><strong>' . View::money($summary['confirmed_renewals']) . '</strong></section>'
            . '<section class="stat"><span>Available for new awards</span><strong>' . View::money($summary['available_new']) . '</strong></section>'
            . '<section class="stat"><span>Allocated</span><strong>' . View::money($summary['allocated_new']) . '</strong></section>'
            . '<section class="stat"><span>Unallocated</span><strong>' . View::money($summary['unallocated_new']) . '</strong></section>'
            . '</div>'
            . $snapshotHtml
            . '<section class="card" style="margin-top:1rem"><h2>Annual plan</h2>'
            . $guidance
            . '<form method="post" action="/admin/planning/' . $id . '">'
            . View::csrfField()
            . '<div class="form-grid">'
            . '<div><label for="total_authorized_amount">Total authorized amount</label>'
            . '<input id="total_authorized_amount" name="total_authorized_amount" type="number" min="0" step="0.01" value="' . View::e($plan['total_authorized_amount']) . '" required></div>'
            . '<div><label for="planned_new_award_count">Planned new awards</label>'
            . '<input id="planned_new_award_count" name="planned_new_award_count" type="number" min="0" value="' . View::e($plan['planned_new_award_count']) . '"></div>'
            . '<div><label for="suggested_new_award_amount">Suggested individual amount</label>'
            . '<input id="suggested_new_award_amount" name="suggested_new_award_amount" type="number" min="0" step="0.01" value="' . View::e($plan['suggested_new_award_amount']) . '"></div>'
            . '<div><label for="planning_status">Planning status</label><select id="planning_status" name="planning_status">'
            . $this->options(['draft' => 'Draft', 'confirmed' => 'Confirmed', 'closed' => 'Closed'], (string) $plan['planning_status'])
            . '</select></div></div>'
            . $ruleText
            . '<div class="form-actions"><button type="submit">Save annual plan</button></div></form></section>'
            . '<section class="card" style="margin-top:1rem"><h2>Original donor intent</h2><p>'
            . nl2br(View::e($plan['original_intent_text'])) . '</p></section>';

        return $this->render($plan['name'], $body, $cycle);
    }

    public function savePlan(array $params): never
    {
        $this->requireAdmin();
        $this->requirePost();
        $cycle = $this->requireCurrentCycle();
        $id = (int) ($params['id'] ?? 0);

        try {
            $total = round((float) ($_POST['total_authorized_amount'] ?? 0), 2);
            $count = trim((string) ($_POST['planned_new_award_count'] ?? ''));
            $suggested = trim((string) ($_POST['suggested_new_award_amount'] ?? ''));
            $status = (string) ($_POST['planning_status'] ?? 'draft');

            if ($total < 0 || !in_array($status, ['draft', 'confirmed', 'closed'], true)) {
                throw new RuntimeException('Annual plan values are invalid.');
            }

            $beforeStmt = $this->pdo->prepare('SELECT * FROM cycle_scholarships WHERE id = ? AND cycle_id = ?');
            $beforeStmt->execute([$id, (int) $cycle['id']]);
            $before = $beforeStmt->fetch();

            if (!$before) {
                throw new RuntimeException('Scholarship plan not found.');
            }

            $summary = (new AllocationService($this->pdo))->budgetSummary($id);
            $committed = (float) $summary['confirmed_renewals'] + (float) $summary['allocated_new'];

            if ($total + 0.00001 < $committed) {
                throw new RuntimeException(
                    'Annual total cannot be lower than confirmed renewals plus existing allocations (' . View::money($committed) . ').'
                );
            }

            $stmt = $this->pdo->prepare(
                'UPDATE cycle_scholarships
                 SET total_authorized_amount = ?,
                     planned_new_award_count = ?,
                     suggested_new_award_amount = ?,
                     planning_status = ?,
                     confirmed_by_user_id = CASE WHEN ? = "confirmed" THEN ? ELSE confirmed_by_user_id END,
                     confirmed_at = CASE WHEN ? = "confirmed" THEN NOW() ELSE confirmed_at END
                 WHERE id = ? AND cycle_id = ?'
            );
            $stmt->execute([
                $total,
                $count === '' ? null : (int) $count,
                $suggested === '' ? null : round((float) $suggested, 2),
                $status,
                $status,
                $this->auth->userId(),
                $status,
                $id,
                (int) $cycle['id'],
            ]);

            $this->audit('planning.updated', 'cycle_scholarship', $id, (int) $cycle['id'], $before, [
                'total_authorized_amount' => $total,
                'planned_new_award_count' => $count === '' ? null : (int) $count,
                'suggested_new_award_amount' => $suggested === '' ? null : (float) $suggested,
                'planning_status' => $status,
            ]);

            Flash::success('Annual scholarship plan saved.');
        } catch (\Throwable $e) {
            Flash::error($e->getMessage());
        }

        $this->redirect('/admin/planning/' . $id);
    }

    public function renewals(): string
    {
        $this->requireAdmin();
        $cycle = $this->requireCurrentCycle();

        $stmt = $this->pdo->prepare(
            "SELECT rc.*, s.name AS scholarship_name, st.display_name AS student_name,
                    ou.name AS program_name, ea.status AS eligibility_status
             FROM renewal_candidates rc
             JOIN scholarships s ON s.id = rc.scholarship_id
             JOIN students st ON st.id = rc.student_id
             JOIN org_units ou ON ou.id = rc.org_unit_id
             LEFT JOIN eligibility_assessments ea ON ea.id = rc.eligibility_assessment_id
             WHERE rc.cycle_id = ?
             ORDER BY s.name, st.last_name, st.first_name"
        );
        $stmt->execute([(int) $cycle['id']]);

        $rows = '';
        foreach ($stmt->fetchAll() as $row) {
            $eligibility = $row['eligibility_status']
                ? View::status($row['eligibility_status'])
                : View::status('insufficient_information');

            $rows .= '<tr>'
                . '<td><strong>' . View::e($row['scholarship_name']) . '</strong></td>'
                . '<td>' . View::e($row['student_name']) . '</td>'
                . '<td>' . View::e($row['program_name']) . '</td>'
                . '<td class="num">' . View::money($row['prior_award_amount']) . '</td>'
                . '<td>' . (int) $row['renewal_year_number'] . '</td>'
                . '<td>' . $eligibility . '</td>'
                . '<td>' . View::status($row['status']) . '</td>'
                . '<td><form method="post" action="/admin/renewals/' . (int) $row['id'] . '">'
                . View::csrfField()
                . '<label class="muted" for="amount_' . (int) $row['id'] . '">Current amount</label>'
                . '<input id="amount_' . (int) $row['id'] . '" name="amount" type="number" min="0" step="0.01" value="' . View::e($row['proposed_current_amount']) . '">'
                . '<div class="actions" style="margin-top:.4rem">'
                . '<button class="small" name="action" value="confirm">Confirm</button>'
                . '<button class="secondary small" name="action" value="hold">Hold</button>'
                . '<button class="secondary small" name="action" value="decline">Decline</button>'
                . '</div></form></td></tr>';
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="8">No renewable scholarship candidates are awaiting review for this cycle.</td></tr>';
        }

        $body = <<<HTML
<div class="page-header">
    <div>
        <h1>Renewable scholarships</h1>
        <p>Review returning recipients before finalizing new-award allocations. Prior amounts are reference only.</p>
    </div>
</div>
<div class="table-wrap">
<table>
<thead><tr><th>Scholarship</th><th>Student</th><th>Program</th><th class="num">Prior amount</th><th>Year</th><th>Eligibility</th><th>Status</th><th>Dean's Office action</th></tr></thead>
<tbody>{$rows}</tbody>
</table>
</div>
HTML;

        return $this->render('Renewals', $body, $cycle);
    }

    public function renewalAction(array $params): never
    {
        $this->requireAdmin();
        $this->requirePost();
        $cycle = $this->requireCurrentCycle();

        $id = (int) ($params['id'] ?? 0);
        $action = (string) ($_POST['action'] ?? '');
        $amountRaw = trim((string) ($_POST['amount'] ?? ''));
        $amount = $amountRaw === '' ? null : round((float) $amountRaw, 2);

        try {
            $service = new RenewalService($this->pdo);

            match ($action) {
                'confirm' => $service->confirm($id, (float) $amount, (int) $this->auth->userId()),
                'hold' => $service->hold($id, $amount, (int) $this->auth->userId()),
                'decline' => $service->decline($id, (int) $this->auth->userId()),
                default => throw new RuntimeException('Unknown renewal action.'),
            };

            $this->audit('renewal.' . $action, 'renewal_candidate', $id, (int) $cycle['id'], null, ['amount' => $amount]);
            Flash::success('Renewal updated.');
        } catch (\Throwable $e) {
            Flash::error($e->getMessage());
        }

        $this->redirect('/admin/renewals');
    }

    private function options(array $values, string $selected): string
    {
        $html = '';
        foreach ($values as $value => $label) {
            $html .= '<option value="' . View::e($value) . '"'
                . ($value === $selected ? ' selected' : '') . '>' . View::e($label) . '</option>';
        }

        return $html;
    }
}

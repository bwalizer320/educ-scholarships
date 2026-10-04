<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Support\Flash;
use App\Support\View;
use RuntimeException;

final class ReviewSetupController extends BaseAdminController
{
    public function allocations(): string
    {
        $this->requireAdmin();
        $cycle = $this->requireCurrentCycle();

        $stmt = $this->pdo->prepare(
            "SELECT ca.*, s.name AS scholarship_name, s.uica_account_number,
                    ou.name AS unit_name, u.display_name AS reviewer_name
             FROM cycle_allocations ca
             JOIN cycle_scholarships cs ON cs.id = ca.cycle_scholarship_id
             JOIN scholarships s ON s.id = cs.scholarship_id
             JOIN org_units ou ON ou.id = ca.org_unit_id
             LEFT JOIN users u ON u.id = ca.primary_reviewer_user_id
             WHERE cs.cycle_id = ?
             ORDER BY s.name, ou.name"
        );
        $stmt->execute([(int) $cycle['id']]);

        $rows = '';
        foreach ($stmt->fetchAll() as $row) {
            $rows .= '<tr>'
                . '<td>' . View::e($row['scholarship_name']) . '<br><span class="muted">UICA ' . View::e($row['uica_account_number']) . '</span></td>'
                . '<td>' . View::e($row['unit_name']) . '</td>'
                . '<td class="num">' . View::money($row['authorized_new_amount']) . '</td>'
                . '<td>' . View::e($row['planned_new_award_count'] ?? '') . '</td>'
                . '<td class="num">' . ($row['suggested_award_amount'] !== null ? View::money($row['suggested_award_amount']) : '—') . '</td>'
                . '<td>' . View::e($row['reviewer_name'] ?? 'Not assigned') . '</td>'
                . '<td>' . View::status($row['status']) . '</td>'
                . '<td><a class="button secondary small" href="/admin/allocations/' . (int) $row['id'] . '">Edit</a></td>'
                . '</tr>';
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="8">No new-award allocations have been created for this cycle.</td></tr>';
        }

        $scholarshipOptions = $this->scholarshipOptions((int) $cycle['id']);
        $unitOptions = $this->unitOptions();

        $body = <<<HTML
<div class="page-header">
    <div>
        <h1>Program allocations</h1>
        <p>Assign the new-award portion of each scholarship to a program, department, or center.</p>
    </div>
</div>

<section class="card">
<h2>Add allocation</h2>
<form method="post" action="/admin/allocations">
    {$this->csrf()}
    <div class="form-grid">
        <div><label for="cycle_scholarship_id">Scholarship</label><select id="cycle_scholarship_id" name="cycle_scholarship_id" required>{$scholarshipOptions}</select></div>
        <div><label for="org_unit_id">Review unit</label><select id="org_unit_id" name="org_unit_id" required>{$unitOptions}</select></div>
        <div><label for="authorized_new_amount">Authorized amount</label><input id="authorized_new_amount" name="authorized_new_amount" type="number" min="0" step="0.01" required></div>
        <div><label for="planned_new_award_count">Planned new awards</label><input id="planned_new_award_count" name="planned_new_award_count" type="number" min="0"></div>
        <div><label for="suggested_award_amount">Suggested award amount</label><input id="suggested_award_amount" name="suggested_award_amount" type="number" min="0" step="0.01"></div>
    </div>
    <div class="form-actions"><button type="submit">Add allocation</button></div>
</form>
</section>

<section class="card" style="margin-top:1rem">
<h2>Current allocations</h2>
<div class="table-wrap">
<table>
<thead><tr><th>Scholarship</th><th>Unit</th><th class="num">Authorized</th><th>Planned awards</th><th class="num">Suggested</th><th>Reviewer</th><th>Status</th><th>Action</th></tr></thead>
<tbody>{$rows}</tbody>
</table>
</div>
</section>
HTML;

        return $this->render('Allocations', $body, $cycle);
    }

    public function createAllocation(): never
    {
        $this->requireAdmin();
        $this->requirePost();
        $cycle = $this->requireCurrentCycle();

        try {
            $cycleScholarshipId = (int) ($_POST['cycle_scholarship_id'] ?? 0);
            $orgUnitId = (int) ($_POST['org_unit_id'] ?? 0);
            $amount = round((float) ($_POST['authorized_new_amount'] ?? 0), 2);
            $count = $this->nullableInt($_POST['planned_new_award_count'] ?? null);
            $suggested = $this->nullableMoney($_POST['suggested_award_amount'] ?? null);

            if ($cycleScholarshipId < 1 || $orgUnitId < 1 || $amount < 0) {
                throw new RuntimeException('Allocation values are invalid.');
            }

            $this->assertAllocationTotal($cycleScholarshipId, $amount, null);

            $stmt = $this->pdo->prepare(
                "INSERT INTO cycle_allocations (
                    cycle_scholarship_id, org_unit_id, authorized_new_amount,
                    planned_new_award_count, suggested_award_amount, status
                 ) VALUES (?, ?, ?, ?, ?, 'draft')"
            );
            $stmt->execute([$cycleScholarshipId, $orgUnitId, $amount, $count, $suggested]);
            $id = (int) $this->pdo->lastInsertId();

            $this->audit('allocation.created', 'cycle_allocation', $id, (int) $cycle['id'], null, [
                'authorized_new_amount' => $amount,
                'org_unit_id' => $orgUnitId,
            ]);
            Flash::success('Allocation created.');
        } catch (\Throwable $e) {
            Flash::error($e->getMessage());
        }

        $this->redirect('/admin/allocations');
    }

    public function editAllocation(array $params): string
    {
        $this->requireAdmin();
        $cycle = $this->requireCurrentCycle();
        $id = (int) ($params['id'] ?? 0);

        $stmt = $this->pdo->prepare(
            "SELECT ca.*, s.name AS scholarship_name, ou.name AS unit_name
             FROM cycle_allocations ca
             JOIN cycle_scholarships cs ON cs.id = ca.cycle_scholarship_id
             JOIN scholarships s ON s.id = cs.scholarship_id
             JOIN org_units ou ON ou.id = ca.org_unit_id
             WHERE ca.id = ? AND cs.cycle_id = ?"
        );
        $stmt->execute([$id, (int) $cycle['id']]);
        $allocation = $stmt->fetch();

        if (!$allocation) {
            http_response_code(404);
            return $this->render('Allocation not found', '<div class="error">Allocation not found.</div>', $cycle);
        }

        $reviewerOptions = $this->reviewerOptions($allocation['primary_reviewer_user_id']);
        $due = $allocation['review_due_at']
            ? date('Y-m-d\TH:i', strtotime((string) $allocation['review_due_at']))
            : '';

        $body = '<div class="page-header"><div><h1>' . View::e($allocation['scholarship_name']) . '</h1>'
            . '<p>' . View::e($allocation['unit_name']) . '</p></div>'
            . '<a class="button secondary" href="/admin/allocations">Back to allocations</a></div>'
            . '<section class="card"><form method="post" action="/admin/allocations/' . $id . '">'
            . View::csrfField()
            . '<div class="form-grid">'
            . '<div><label for="authorized_new_amount">Authorized amount</label><input id="authorized_new_amount" name="authorized_new_amount" type="number" min="0" step="0.01" value="' . View::e($allocation['authorized_new_amount']) . '" required></div>'
            . '<div><label for="planned_new_award_count">Planned awards</label><input id="planned_new_award_count" name="planned_new_award_count" type="number" min="0" value="' . View::e($allocation['planned_new_award_count']) . '"></div>'
            . '<div><label for="suggested_award_amount">Suggested award</label><input id="suggested_award_amount" name="suggested_award_amount" type="number" min="0" step="0.01" value="' . View::e($allocation['suggested_award_amount']) . '"></div>'
            . '<div><label for="primary_reviewer_user_id">Primary reviewer</label><select id="primary_reviewer_user_id" name="primary_reviewer_user_id">' . $reviewerOptions . '</select></div>'
            . '<div><label for="review_due_at">Deadline override</label><input id="review_due_at" name="review_due_at" type="datetime-local" value="' . View::e($due) . '"><p class="muted">Leave blank to use the College-wide deadline.</p></div>'
            . '<div><label for="status">Allocation status</label><select id="status" name="status">'
            . $this->options(['draft'=>'Draft','confirmed'=>'Confirmed','review_open'=>'Review open','submitted'=>'Submitted','closed'=>'Closed'], (string) $allocation['status'])
            . '</select></div></div>'
            . '<div class="form-actions"><button type="submit">Save allocation</button></div></form></section>';

        return $this->render('Edit allocation', $body, $cycle);
    }

    public function saveAllocation(array $params): never
    {
        $this->requireAdmin();
        $this->requirePost();
        $cycle = $this->requireCurrentCycle();
        $id = (int) ($params['id'] ?? 0);

        try {
            $beforeStmt = $this->pdo->prepare('SELECT * FROM cycle_allocations WHERE id = ?');
            $beforeStmt->execute([$id]);
            $before = $beforeStmt->fetch();

            if (!$before) {
                throw new RuntimeException('Allocation not found.');
            }

            $amount = round((float) ($_POST['authorized_new_amount'] ?? 0), 2);
            $count = $this->nullableInt($_POST['planned_new_award_count'] ?? null);
            $suggested = $this->nullableMoney($_POST['suggested_award_amount'] ?? null);
            $reviewer = $this->nullableInt($_POST['primary_reviewer_user_id'] ?? null);
            $deadline = $this->toSqlDate($_POST['review_due_at'] ?? null);
            $status = (string) ($_POST['status'] ?? 'draft');

            if (!in_array($status, ['draft','confirmed','review_open','submitted','closed'], true)) {
                throw new RuntimeException('Invalid allocation status.');
            }

            $this->assertAllocationTotal((int) $before['cycle_scholarship_id'], $amount, $id);

            $stmt = $this->pdo->prepare(
                'UPDATE cycle_allocations
                 SET authorized_new_amount = ?, planned_new_award_count = ?,
                     suggested_award_amount = ?, primary_reviewer_user_id = ?,
                     review_due_at = ?, status = ?
                 WHERE id = ?'
            );
            $stmt->execute([$amount, $count, $suggested, $reviewer, $deadline, $status, $id]);

            $this->audit('allocation.updated', 'cycle_allocation', $id, (int) $cycle['id'], $before, [
                'authorized_new_amount'=>$amount,
                'planned_new_award_count'=>$count,
                'suggested_award_amount'=>$suggested,
                'primary_reviewer_user_id'=>$reviewer,
                'review_due_at'=>$deadline,
                'status'=>$status,
            ]);
            Flash::success('Allocation saved.');
        } catch (\Throwable $e) {
            Flash::error($e->getMessage());
        }

        $this->redirect('/admin/allocations/' . $id);
    }

    public function reviewers(): string
    {
        $this->requireAdmin();
        $cycle = $this->requireCurrentCycle();

        $stmt = $this->pdo->prepare(
            "SELECT ru.*, ou.name AS unit_name, ou.unit_type, u.display_name AS reviewer_name
             FROM cycle_review_units ru
             JOIN org_units ou ON ou.id = ru.org_unit_id
             JOIN users u ON u.id = ru.primary_reviewer_user_id
             WHERE ru.cycle_id = ?
             ORDER BY ou.unit_type, ou.name"
        );
        $stmt->execute([(int) $cycle['id']]);

        $rows = '';
        foreach ($stmt->fetchAll() as $row) {
            $rows .= '<tr>'
                . '<td>' . View::e($row['unit_name']) . '</td>'
                . '<td>' . View::e(ucfirst($row['unit_type'])) . '</td>'
                . '<td>' . View::e($row['reviewer_name']) . '</td>'
                . '<td>' . View::e(ucfirst($row['review_method'])) . '</td>'
                . '<td>' . View::e(date('M j, Y g:i a', strtotime((string) $row['due_at']))) . '</td>'
                . '<td>' . View::status($row['status']) . '</td>'
                . '</tr>';
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="6">No review units have been configured yet.</td></tr>';
        }

        $unitOptions = $this->unitOptions();
        $reviewerOptions = $this->reviewerOptions(null);
        $defaultDue = $cycle['program_review_due_at']
            ? date('Y-m-d\TH:i', strtotime((string) $cycle['program_review_due_at']))
            : '';

        $body = <<<HTML
<div class="page-header">
<div><h1>Reviewers</h1><p>Each review unit has one primary reviewer. Change the assignment here when staffing changes.</p></div>
</div>
<section class="card">
<h2>Add or update review unit</h2>
<form method="post" action="/admin/reviewers">
{$this->csrf()}
<div class="form-grid">
<div><label for="org_unit_id">Program / unit</label><select id="org_unit_id" name="org_unit_id" required>{$unitOptions}</select></div>
<div><label for="primary_reviewer_user_id">Primary reviewer</label><select id="primary_reviewer_user_id" name="primary_reviewer_user_id" required>{$reviewerOptions}</select></div>
<div><label for="review_method">Review method</label><select id="review_method" name="review_method"><option value="selection">Selection</option><option value="rubric">Rubric</option></select></div>
<div><label for="due_at">Deadline</label><input id="due_at" name="due_at" type="datetime-local" value="{$this->e($defaultDue)}" required></div>
</div>
<div class="form-actions"><button type="submit">Save reviewer assignment</button></div>
</form>
</section>
<section class="card" style="margin-top:1rem">
<h2>Current review units</h2>
<div class="table-wrap"><table>
<thead><tr><th>Unit</th><th>Type</th><th>Reviewer</th><th>Method</th><th>Deadline</th><th>Status</th></tr></thead>
<tbody>{$rows}</tbody>
</table></div>
</section>
HTML;

        return $this->render('Reviewers', $body, $cycle);
    }

    public function saveReviewer(): never
    {
        $this->requireAdmin();
        $this->requirePost();
        $cycle = $this->requireCurrentCycle();

        try {
            $org = (int) ($_POST['org_unit_id'] ?? 0);
            $reviewer = (int) ($_POST['primary_reviewer_user_id'] ?? 0);
            $method = (string) ($_POST['review_method'] ?? 'selection');
            $due = $this->toSqlDate($_POST['due_at'] ?? null);

            if ($org < 1 || $reviewer < 1 || $due === null || !in_array($method, ['selection','rubric'], true)) {
                throw new RuntimeException('Reviewer assignment is incomplete.');
            }

            $stmt = $this->pdo->prepare(
                "INSERT INTO cycle_review_units (
                    cycle_id, org_unit_id, primary_reviewer_user_id, review_method, due_at, status
                 ) VALUES (?, ?, ?, ?, ?, 'not_started')
                 ON DUPLICATE KEY UPDATE
                    primary_reviewer_user_id = VALUES(primary_reviewer_user_id),
                    review_method = VALUES(review_method),
                    due_at = VALUES(due_at)"
            );
            $stmt->execute([(int) $cycle['id'], $org, $reviewer, $method, $due]);

            $assignment = $this->pdo->prepare(
                "INSERT INTO user_unit_assignments (
                    user_id, org_unit_id, assignment_type, cycle_id, active
                 ) VALUES (?, ?, 'reviewer', ?, 1)"
            );
            $assignment->execute([$reviewer, $org, (int) $cycle['id']]);

            $this->audit('reviewer.assigned', 'org_unit', $org, (int) $cycle['id'], null, [
                'reviewer_user_id'=>$reviewer,
                'review_method'=>$method,
                'due_at'=>$due,
            ]);
            Flash::success('Reviewer assignment saved.');
        } catch (\Throwable $e) {
            Flash::error($e->getMessage());
        }

        $this->redirect('/admin/reviewers');
    }

    public function rubrics(): string
    {
        $this->requireAdmin();
        $cycle = $this->requireCurrentCycle();

        $stmt = $this->pdo->prepare(
            "SELECT r.*, ou.name AS unit_name,
                    (SELECT COUNT(*) FROM rubric_items ri WHERE ri.rubric_id = r.id) AS item_count,
                    (SELECT COALESCE(SUM(max_points),0) FROM rubric_items ri WHERE ri.rubric_id = r.id) AS total_points
             FROM rubrics r
             JOIN org_units ou ON ou.id = r.org_unit_id
             WHERE r.cycle_id = ?
             ORDER BY ou.name"
        );
        $stmt->execute([(int) $cycle['id']]);

        $rows = '';
        foreach ($stmt->fetchAll() as $row) {
            $rows .= '<tr>'
                . '<td><a href="/admin/rubrics/' . (int) $row['id'] . '">' . View::e($row['unit_name']) . '</a></td>'
                . '<td>' . View::e($row['name']) . '</td>'
                . '<td>' . (int) $row['item_count'] . '</td>'
                . '<td>' . View::e($row['total_points']) . '</td>'
                . '<td>' . View::status($row['status']) . '</td>'
                . '<td><a class="button secondary small" href="/admin/rubrics/' . (int) $row['id'] . '">Edit</a></td>'
                . '</tr>';
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="6">No rubrics configured for this cycle.</td></tr>';
        }

        $body = '<div class="page-header"><div><h1>Rubrics</h1><p>Rubrics are cycle-specific and copied forward during rollover.</p></div></div>'
            . '<div class="table-wrap"><table><thead><tr><th>Program</th><th>Rubric</th><th>Items</th><th>Total points</th><th>Status</th><th>Action</th></tr></thead><tbody>'
            . $rows . '</tbody></table></div>';

        return $this->render('Rubrics', $body, $cycle);
    }

    public function rubric(array $params): string
    {
        $this->requireAdmin();
        $cycle = $this->requireCurrentCycle();
        $id = (int) ($params['id'] ?? 0);

        $stmt = $this->pdo->prepare(
            "SELECT r.*, ou.name AS unit_name
             FROM rubrics r JOIN org_units ou ON ou.id = r.org_unit_id
             WHERE r.id = ? AND r.cycle_id = ?"
        );
        $stmt->execute([$id, (int) $cycle['id']]);
        $rubric = $stmt->fetch();

        if (!$rubric) {
            http_response_code(404);
            return $this->render('Rubric not found', '<div class="error">Rubric not found.</div>', $cycle);
        }

        $itemsStmt = $this->pdo->prepare('SELECT * FROM rubric_items WHERE rubric_id = ? ORDER BY sort_order, id');
        $itemsStmt->execute([$id]);
        $rows = '';

        foreach ($itemsStmt->fetchAll() as $item) {
            $rows .= '<tr><td>' . View::e($item['label']) . '</td><td>' . View::e($item['description']) . '</td><td>' . View::e($item['max_points']) . '</td><td>' . (int) $item['sort_order'] . '</td></tr>';
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="4">No rubric criteria yet.</td></tr>';
        }

        $confirmLabel = $rubric['status'] === 'confirmed' ? 'Mark draft' : 'Confirm rubric';
        $nextStatus = $rubric['status'] === 'confirmed' ? 'draft' : 'confirmed';

        $body = '<div class="page-header"><div><h1>' . View::e($rubric['unit_name']) . '</h1><p>' . View::e($rubric['name']) . '</p></div>'
            . '<form method="post" action="/admin/rubrics/' . $id . '/status">' . View::csrfField()
            . '<input type="hidden" name="status" value="' . View::e($nextStatus) . '"><button type="submit">' . View::e($confirmLabel) . '</button></form></div>'
            . '<section class="card"><h2>Add criterion</h2><form method="post" action="/admin/rubrics/' . $id . '/items">' . View::csrfField()
            . '<div class="form-grid"><div><label for="label">Criterion</label><input id="label" name="label" required></div>'
            . '<div><label for="max_points">Maximum points</label><input id="max_points" name="max_points" type="number" min="0" step="0.25" required></div>'
            . '<div><label for="sort_order">Order</label><input id="sort_order" name="sort_order" type="number" min="0" value="10"></div></div>'
            . '<label for="description">Description</label><textarea id="description" name="description"></textarea>'
            . '<div class="form-actions"><button type="submit">Add criterion</button></div></form></section>'
            . '<section class="card" style="margin-top:1rem"><h2>Criteria</h2><div class="table-wrap"><table><thead><tr><th>Criterion</th><th>Description</th><th>Max points</th><th>Order</th></tr></thead><tbody>'
            . $rows . '</tbody></table></div></section>';

        return $this->render('Rubric', $body, $cycle);
    }

    public function addRubricItem(array $params): never
    {
        $this->requireAdmin();
        $this->requirePost();
        $cycle = $this->requireCurrentCycle();
        $rubricId = (int) ($params['id'] ?? 0);

        try {
            $label = trim((string) ($_POST['label'] ?? ''));
            $description = trim((string) ($_POST['description'] ?? ''));
            $max = (float) ($_POST['max_points'] ?? 0);
            $sort = (int) ($_POST['sort_order'] ?? 0);

            if ($label === '' || $max < 0) {
                throw new RuntimeException('Rubric criterion is invalid.');
            }

            $stmt = $this->pdo->prepare(
                'INSERT INTO rubric_items (rubric_id, label, description, max_points, sort_order)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([$rubricId, $label, $description !== '' ? $description : null, $max, $sort]);
            $itemId = (int) $this->pdo->lastInsertId();

            $this->pdo->prepare("UPDATE rubrics SET status = 'draft' WHERE id = ? AND cycle_id = ?")
                ->execute([$rubricId, (int) $cycle['id']]);

            $this->audit('rubric.item_added', 'rubric_item', $itemId, (int) $cycle['id']);
            Flash::success('Rubric criterion added.');
        } catch (\Throwable $e) {
            Flash::error($e->getMessage());
        }

        $this->redirect('/admin/rubrics/' . $rubricId);
    }

    public function setRubricStatus(array $params): never
    {
        $this->requireAdmin();
        $this->requirePost();
        $cycle = $this->requireCurrentCycle();
        $id = (int) ($params['id'] ?? 0);
        $status = (string) ($_POST['status'] ?? 'draft');

        if (!in_array($status, ['draft','confirmed'], true)) {
            $status = 'draft';
        }

        $stmt = $this->pdo->prepare('UPDATE rubrics SET status = ? WHERE id = ? AND cycle_id = ?');
        $stmt->execute([$status, $id, (int) $cycle['id']]);

        $this->audit('rubric.status_changed', 'rubric', $id, (int) $cycle['id'], null, ['status'=>$status]);
        Flash::success('Rubric status updated.');
        $this->redirect('/admin/rubrics/' . $id);
    }

    private function assertAllocationTotal(int $cycleScholarshipId, float $newAmount, ?int $excludeId): void
    {
        $planStmt = $this->pdo->prepare(
            "SELECT cs.total_authorized_amount,
                    COALESCE((SELECT SUM(proposed_current_amount)
                              FROM renewal_candidates
                              WHERE cycle_scholarship_id = cs.id AND status = 'confirmed'),0) AS renewals
             FROM cycle_scholarships cs WHERE cs.id = ?"
        );
        $planStmt->execute([$cycleScholarshipId]);
        $plan = $planStmt->fetch();

        if (!$plan) {
            throw new RuntimeException('Scholarship annual plan not found.');
        }

        $sql = 'SELECT COALESCE(SUM(authorized_new_amount),0) FROM cycle_allocations WHERE cycle_scholarship_id = ?';
        $params = [$cycleScholarshipId];

        if ($excludeId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeId;
        }

        $sumStmt = $this->pdo->prepare($sql);
        $sumStmt->execute($params);
        $other = (float) $sumStmt->fetchColumn();

        $available = (float) $plan['total_authorized_amount'] - (float) $plan['renewals'];

        if ($other + $newAmount > $available + 0.00001) {
            throw new RuntimeException('This allocation would exceed the scholarship amount available for new awards.');
        }
    }

    private function scholarshipOptions(int $cycleId): string
    {
        $stmt = $this->pdo->prepare(
            'SELECT cs.id, s.name, s.uica_account_number
             FROM cycle_scholarships cs JOIN scholarships s ON s.id = cs.scholarship_id
             WHERE cs.cycle_id = ? ORDER BY s.name'
        );
        $stmt->execute([$cycleId]);

        $html = '<option value="">Select scholarship</option>';
        foreach ($stmt->fetchAll() as $row) {
            $html .= '<option value="' . (int) $row['id'] . '">' . View::e($row['name']) . ' — ' . View::e($row['uica_account_number']) . '</option>';
        }
        return $html;
    }

    private function unitOptions(): string
    {
        $rows = $this->pdo->query(
            "SELECT id, name, unit_type FROM org_units
             WHERE active = 1 AND unit_type IN ('department','program','center')
             ORDER BY unit_type, name"
        )->fetchAll();

        $html = '<option value="">Select program or unit</option>';
        foreach ($rows as $row) {
            $html .= '<option value="' . (int) $row['id'] . '">' . View::e($row['name']) . ' (' . View::e($row['unit_type']) . ')</option>';
        }
        return $html;
    }

    private function reviewerOptions(mixed $selected): string
    {
        $rows = $this->pdo->query(
            "SELECT id, display_name, staff_role FROM users
             WHERE active = 1 AND person_type = 'staff'
             ORDER BY last_name, first_name"
        )->fetchAll();

        $html = '<option value="">Select reviewer</option>';
        foreach ($rows as $row) {
            $html .= '<option value="' . (int) $row['id'] . '"'
                . ((string) $selected === (string) $row['id'] ? ' selected' : '') . '>'
                . View::e($row['display_name']) . ' — ' . View::e(str_replace('_', ' ', $row['staff_role'] ?? 'staff'))
                . '</option>';
        }
        return $html;
    }

    private function options(array $values, string $selected): string
    {
        $html = '';
        foreach ($values as $value=>$label) {
            $html .= '<option value="' . View::e($value) . '"' . ($value === $selected ? ' selected' : '') . '>' . View::e($label) . '</option>';
        }
        return $html;
    }

    private function nullableInt(mixed $value): ?int
    {
        $value = trim((string) $value);
        return $value === '' ? null : (int) $value;
    }

    private function nullableMoney(mixed $value): ?float
    {
        $value = trim((string) $value);
        return $value === '' ? null : round((float) $value, 2);
    }

    private function toSqlDate(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $timestamp = strtotime($value);
        return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
    }

    private function csrf(): string { return View::csrfField(); }
    private function e(mixed $value): string { return View::e($value); }
}

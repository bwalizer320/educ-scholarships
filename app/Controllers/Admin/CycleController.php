<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\Cycle\CycleRolloverService;
use App\Support\Flash;
use App\Support\View;
use RuntimeException;

final class CycleController extends BaseAdminController
{
    public function index(): string
    {
        $this->requireAdmin();

        $cycles = $this->pdo->query(
            'SELECT * FROM academic_cycles ORDER BY start_year DESC'
        )->fetchAll();

        $rows = '';
        foreach ($cycles as $cycle) {
            $rows .= '<tr>'
                . '<td><a href="/admin/cycles/' . (int) $cycle['id'] . '/setup">' . View::e($cycle['label']) . '</a></td>'
                . '<td>' . View::status($cycle['status']) . '</td>'
                . '<td>' . ((int) $cycle['is_current'] === 1 ? '<strong>Current</strong>' : '') . '</td>'
                . '<td><div class="actions">'
                . '<a class="button secondary small" href="/admin/cycles/' . (int) $cycle['id'] . '/setup">Setup</a>'
                . (((int) $cycle['is_current'] === 1) ? '' :
                    '<form method="post" action="/admin/cycles/' . (int) $cycle['id'] . '/current">'
                    . View::csrfField()
                    . '<button class="small" type="submit">Make current</button></form>')
                . '</div></td>'
                . '</tr>';
        }

        $sourceOptions = '<option value="">Start blank</option>';
        foreach ($cycles as $cycle) {
            $sourceOptions .= '<option value="' . (int) $cycle['id'] . '">'
                . View::e($cycle['label']) . ' — copy configuration</option>';
        }

        $body = <<<HTML
<div class="page-header">
    <div>
        <h1>Academic cycles</h1>
        <p>Create the next scholarship year, copy prior configuration, and choose the active cycle.</p>
    </div>
</div>

<section class="card">
    <h2>Create academic cycle</h2>
    <form method="post" action="/admin/cycles">
        {$this->csrf()}
        <div class="form-grid">
            <div>
                <label for="start_year">Start year</label>
                <input id="start_year" name="start_year" type="number" min="2020" max="2100" required>
            </div>
            <div>
                <label for="source_cycle_id">Copy from</label>
                <select id="source_cycle_id" name="source_cycle_id">{$sourceOptions}</select>
            </div>
        </div>
        <div class="form-actions"><button type="submit">Create cycle</button></div>
    </form>
</section>

<section class="card" style="margin-top:1rem">
    <h2>Cycles</h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Cycle</th><th>Status</th><th>Active</th><th>Actions</th></tr></thead>
            <tbody>{$rows}</tbody>
        </table>
    </div>
</section>
HTML;

        return $this->render('Academic cycles', $body);
    }

    public function create(): never
    {
        $this->requireAdmin();
        $this->requirePost();

        try {
            $startYear = (int) ($_POST['start_year'] ?? 0);
            $endYear = $startYear + 1;
            $label = $startYear . '-' . substr((string) $endYear, -2);
            $sourceCycleId = (int) ($_POST['source_cycle_id'] ?? 0);

            $service = new CycleRolloverService($this->pdo);
            $cycleId = $sourceCycleId > 0
                ? $service->createFromPrior($sourceCycleId, $label, $startYear, $endYear)
                : $service->createInitial($label, $startYear, $endYear);

            $this->audit(
                'cycle.created',
                'academic_cycle',
                $cycleId,
                $cycleId,
                null,
                ['label' => $label, 'source_cycle_id' => $sourceCycleId ?: null]
            );

            Flash::success("Created {$label}. Review the setup checklist before opening the cycle.");
            $this->redirect('/admin/cycles/' . $cycleId . '/setup');
        } catch (\Throwable $e) {
            Flash::error($e->getMessage());
            $this->redirect('/admin/cycles');
        }
    }

    public function makeCurrent(array $params): never
    {
        $this->requireAdmin();
        $this->requirePost();

        $cycleId = (int) ($params['id'] ?? 0);

        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec('UPDATE academic_cycles SET is_current = 0 WHERE is_current = 1');
            $stmt = $this->pdo->prepare('UPDATE academic_cycles SET is_current = 1 WHERE id = ?');
            $stmt->execute([$cycleId]);

            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('Academic cycle not found.');
            }

            $this->pdo->commit();
            $this->audit('cycle.current_changed', 'academic_cycle', $cycleId, $cycleId);
            Flash::success('Current academic cycle updated.');
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            Flash::error($e->getMessage());
        }

        $this->redirect('/admin/cycles');
    }

    public function setup(array $params): string
    {
        $this->requireAdmin();

        $cycleId = (int) ($params['id'] ?? 0);
        $stmt = $this->pdo->prepare('SELECT * FROM academic_cycles WHERE id = ?');
        $stmt->execute([$cycleId]);
        $cycle = $stmt->fetch();

        if (!$cycle) {
            http_response_code(404);
            return $this->render('Cycle not found', '<div class="error">Academic cycle not found.</div>');
        }

        $checkStmt = $this->pdo->prepare(
            'SELECT * FROM cycle_checklist_items WHERE cycle_id = ? ORDER BY sort_order, id'
        );
        $checkStmt->execute([$cycleId]);

        $items = '';
        foreach ($checkStmt->fetchAll() as $item) {
            $next = $item['status'] === 'confirmed' ? 'not_started' : 'confirmed';
            $button = $item['status'] === 'confirmed' ? 'Mark incomplete' : 'Confirm';

            $items .= '<div class="check-item">'
                . '<div><strong>' . View::e($item['label']) . '</strong><br>'
                . View::status($item['status']) . '</div>'
                . '<form method="post" action="/admin/cycles/' . $cycleId . '/checklist/' . (int) $item['id'] . '">'
                . View::csrfField()
                . '<input type="hidden" name="status" value="' . View::e($next) . '">'
                . '<button class="secondary small" type="submit">' . View::e($button) . '</button>'
                . '</form></div>';
        }

        $due = $cycle['program_review_due_at']
            ? date('Y-m-d\TH:i', strtotime((string) $cycle['program_review_due_at']))
            : '';
        $thank = $cycle['thank_you_due_at']
            ? date('Y-m-d\TH:i', strtotime((string) $cycle['thank_you_due_at']))
            : '';

        $body = <<<HTML
<div class="page-header">
    <div>
        <h1>{$this->e($cycle['label'])} setup</h1>
        <p>Complete the annual rollover checklist before program review opens.</p>
    </div>
    <a class="button secondary" href="/admin/cycles">All cycles</a>
</div>

<section class="card">
    <h2>Cycle dates</h2>
    <form method="post" action="/admin/cycles/{$cycleId}/dates">
        {$this->csrf()}
        <div class="form-grid">
            <div>
                <label for="program_review_due_at">Program recommendation deadline</label>
                <input id="program_review_due_at" name="program_review_due_at" type="datetime-local" value="{$this->e($due)}">
            </div>
            <div>
                <label for="thank_you_due_at">Thank-you letter deadline</label>
                <input id="thank_you_due_at" name="thank_you_due_at" type="datetime-local" value="{$this->e($thank)}">
            </div>
        </div>
        <div class="form-actions"><button type="submit">Save dates</button></div>
    </form>
</section>

<section style="margin-top:1rem">
    <h2>New-cycle checklist</h2>
    <div class="checklist">{$items}</div>
</section>
HTML;

        return $this->render($cycle['label'] . ' setup', $body, $cycle);
    }

    public function checklist(array $params): never
    {
        $this->requireAdmin();
        $this->requirePost();

        $cycleId = (int) ($params['id'] ?? 0);
        $itemId = (int) ($params['item'] ?? 0);
        $status = (string) ($_POST['status'] ?? 'not_started');

        if (!in_array($status, ['not_started', 'in_progress', 'confirmed', 'not_applicable'], true)) {
            $status = 'not_started';
        }

        $stmt = $this->pdo->prepare(
            'UPDATE cycle_checklist_items
             SET status = ?,
                 confirmed_by_user_id = ?,
                 confirmed_at = CASE WHEN ? = "confirmed" THEN NOW() ELSE NULL END
             WHERE id = ? AND cycle_id = ?'
        );
        $stmt->execute([$status, $this->auth->userId(), $status, $itemId, $cycleId]);

        $this->audit('cycle.checklist_updated', 'cycle_checklist_item', $itemId, $cycleId, null, ['status' => $status]);
        Flash::success('Checklist updated.');
        $this->redirect('/admin/cycles/' . $cycleId . '/setup');
    }

    public function saveDates(array $params): never
    {
        $this->requireAdmin();
        $this->requirePost();

        $cycleId = (int) ($params['id'] ?? 0);
        $programDue = $this->toSqlDate($_POST['program_review_due_at'] ?? null);
        $thankDue = $this->toSqlDate($_POST['thank_you_due_at'] ?? null);

        $stmt = $this->pdo->prepare(
            'UPDATE academic_cycles
             SET program_review_due_at = ?, thank_you_due_at = ?
             WHERE id = ?'
        );
        $stmt->execute([$programDue, $thankDue, $cycleId]);

        $this->audit(
            'cycle.dates_updated',
            'academic_cycle',
            $cycleId,
            $cycleId,
            null,
            ['program_review_due_at' => $programDue, 'thank_you_due_at' => $thankDue]
        );
        Flash::success('Cycle dates saved.');
        $this->redirect('/admin/cycles/' . $cycleId . '/setup');
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

    private function csrf(): string
    {
        return View::csrfField();
    }

    private function e(mixed $value): string
    {
        return View::e($value);
    }
}

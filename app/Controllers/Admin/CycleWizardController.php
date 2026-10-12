<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\Imports\AnnualAwardAuthorityImportService;
use App\Services\Imports\SimpleAllocationImportService;
use App\Database\TestDataCleaner;
use App\Support\Env;
use App\Storage\LocalFileStorage;
use App\Support\Flash;
use App\Support\View;
use RuntimeException;

/**
 * A single home for preparing each scholarship cycle.
 * Keeps the underlying import/verification services and access checks intact.
 */
final class CycleWizardController extends BaseAdminController
{
    private const STEPS = [
        1 => ['Academic year', 'Dates and active cycle'],
        2 => ['Load awards', 'Upload your award authority file'],
        3 => ['Assign reviewers', 'Confirm allocations and program leads'],
        4 => ['Applicants', 'Import the candidate pool'],
        5 => ['Eligibility', 'Import enrollment data'],
        6 => ['Open review', 'Check and start recommendations'],
    ];

    public function current(): never
    {
        $this->requireAdmin();
        $cycle = $this->currentCycle();
        if (!$cycle) {
            $this->redirect('/admin/cycles');
        }
        $step = max(1, min(6, (int)($_GET['step'] ?? 1)));
        $this->redirect('/admin/cycles/' . (int)$cycle['id'] . '/setup?step=' . $step);
    }

    public function index(array $params): string
    {
        $this->requireAdmin();
        $cycle = $this->findCycle((int)($params['id'] ?? 0));
        $cycleId = (int)$cycle['id'];
        $step = max(1, min(6, (int)($_GET['step'] ?? 1)));
        $counts = $this->counts($cycleId);

        $navigation = '';
        foreach (self::STEPS as $n => [$name, $description]) {
            $selected = $step === $n;
            $classes = 'workflow-step' . ($selected ? ' primary' : '');
            $navigation .= '<a class="' . $classes . '" href="/admin/cycles/' . $cycleId . '/setup?step=' . $n . '"'
                . ($selected ? ' aria-current="step"' : '') . '>'
                . '<span class="step-number">' . str_pad((string)$n, 2, '0', STR_PAD_LEFT) . '</span>'
                . '<span><strong>' . View::e($name) . '</strong><span>' . View::e($description) . '</span></span></a>';
        }
        $content = match ($step) {
            1 => $this->yearStep($cycle),
            2 => $this->awardsStep($cycle, $counts),
            3 => $this->reviewersStep($cycle, $counts),
            4 => $this->applicantsStep($cycle, $counts),
            5 => $this->eligibilityStep($cycle, $counts),
            default => $this->finishStep($cycle, $counts),
        };
        $back = $step > 1
            ? '<a class="button secondary" href="/admin/cycles/' . $cycleId . '/setup?step=' . ($step - 1) . '">Back</a>' : '';
        $next = $step < 6
            ? '<a class="button" href="/admin/cycles/' . $cycleId . '/setup?step=' . ($step + 1) . '">Continue →</a>'
            : '<a class="button secondary" href="/awards/available">View available awards</a>';
        $body = '<div class="page-header"><div><div class="eyebrow"><span class="eyebrow-dot"></span>'
            . View::e($cycle['label']) . ' · Guided setup · Step ' . $step . ' of 6</div>'
            . '<h1>Set up the scholarship cycle</h1>'
            . '<p>Upload the information, confirm assignments, and open the review period—all from one workspace.</p>'
            . '</div><a class="button secondary" href="/admin/cycles">Other years</a></div>'
            . '<div class="workflow-grid" aria-label="Cycle setup steps">' . $navigation . '</div>'
            . '<div style="margin-top:1.1rem">' . $content . '</div>'
            . '<div class="actions" style="margin-top:1.1rem;justify-content:space-between">' . $back . $next . '</div>';
        return $this->render('Guided cycle setup', $body, $cycle);
    }

    /**
     * A guarded reset in the wizard, intentionally never automatic.
     * TestDataCleaner rejects production mode or actual student emails.
     */
    public function resetDemo(array $params): never
    {
        $this->requireAdmin();
        $this->requirePost();
        $cycle = $this->findCycle((int)($params['id'] ?? 0));

        try {
            $entered = trim((string)($_POST['confirmation'] ?? ''));
            if ($entered !== 'DELETE ' . $cycle['label']) {
                throw new RuntimeException('Confirmation did not match. Nothing was deleted.');
            }
            $preview = (new TestDataCleaner($this->pdo))->clean((string)$cycle['label']);
            $this->audit('cycle_wizard.demo_reset', 'academic_cycle', (int)$cycle['id'], (int)$cycle['id'],
                null, ['counts'=>$preview['counts']]);
            Flash::success('Demo records cleared for ' . $cycle['label']
                . '. Department, program, academic year, term and user configuration was preserved.');
        } catch (\Throwable $e) {
            Flash::error($e->getMessage());
        }
        $this->redirect($this->url($cycle, 1));
    }

    public function uploadAwards(array $params): never
    {
        $this->requireAdmin();
        $this->requirePost();
        $cycle = $this->findCycle((int)($params['id'] ?? 0));
        $this->requireActive($cycle);

        try {
            $file = $_FILES['awards_file'] ?? null;
            if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Choose a file to upload.');
            }
            $filename = (string)$file['name'];
            $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            $kind = (string)($_POST['file_kind'] ?? '');
            if ($kind === 'standard') {
                if (!in_array($extension, ['csv','tsv','xls','xlsx'], true)) {
                    throw new RuntimeException('Standard allocations must be CSV, TSV, XLS, or XLSX.');
                }
                $result = (new SimpleAllocationImportService($this->pdo))->import(
                    (string)$file['tmp_name'], $filename, $cycle
                );
                $message = $result['rows'] . ' award allocation rows imported ('
                    . $result['created'] . ' new, ' . $result['updated'] . ' updated).';
            } elseif ($kind === 'fy27') {
                if (!in_array($extension, ['xls','xlsx'], true)) {
                    throw new RuntimeException('Annual award authority must be an XLS or XLSX workbook.');
                }
                if ((int)($file['size'] ?? 0) > 25 * 1024 * 1024) {
                    throw new RuntimeException('Award workbook exceeds the 25 MB limit.');
                }
                $result = (new AnnualAwardAuthorityImportService($this->pdo, new LocalFileStorage()))->import(
                    (int)$cycle['id'], (int)$this->auth->userId(), (string)$file['tmp_name'], $filename
                );
                $message = (int)$result['authority_rows'] . ' award authority rows imported. '
                    . 'Next, assign funds to departments and programs.';
            } else {
                throw new RuntimeException('Choose the correct file format.');
            }
            $this->audit('cycle_wizard.awards_imported', 'academic_cycle', (int)$cycle['id'], (int)$cycle['id']);
            Flash::success($message);
            $this->redirect($this->url($cycle, 3));
        } catch (\Throwable $e) {
            Flash::error($e->getMessage());
            $this->redirect($this->url($cycle, 2));
        }
    }

    public function saveAssignment(array $params): never
    {
        $this->requireAdmin();
        $this->requirePost();
        $cycle = $this->findCycle((int)($params['id'] ?? 0));
        $this->requireActive($cycle);
        try {
            $fundId = (int)($_POST['cycle_scholarship_id'] ?? 0);
            $unitId = (int)($_POST['org_unit_id'] ?? 0);
            $reviewerId = (int)($_POST['reviewer_id'] ?? 0);
            $amount = trim((string)($_POST['allocation_amount'] ?? ''));
            $countText = trim((string)($_POST['award_count'] ?? ''));
            if (!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', $amount) || (float)$amount <= 0) {
                throw new RuntimeException('Enter a positive allocation amount.');
            }
            $count = $countText === '' ? null : filter_var($countText, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
            if ($count === false) {
                throw new RuntimeException('Planned award count must be a positive whole number.');
            }

            $this->pdo->beginTransaction();
            $fund = $this->pdo->prepare('SELECT id, total_authorized_amount FROM cycle_scholarships WHERE id = ? AND cycle_id = ? FOR UPDATE');
            $fund->execute([$fundId, (int)$cycle['id']]);
            $fundRow = $fund->fetch();
            if (!$fundRow) {
                throw new RuntimeException('Choose a scholarship from this academic year.');
            }
            $unitStmt = $this->pdo->prepare("SELECT id FROM org_units WHERE id = ? AND unit_type IN ('department','program','center') AND active = 1");
            $unitStmt->execute([$unitId]);
            if (!$unitStmt->fetchColumn()) {
                throw new RuntimeException('Choose a valid department or program.');
            }

            if ($reviewerId > 0) {
                $reviewerStmt = $this->pdo->prepare(
                    "SELECT id FROM users WHERE id = ? AND person_type = 'staff' AND active = 1
                     AND staff_role IN ('system_admin','deans_office_admin','program_coordinator')"
                );
                $reviewerStmt->execute([$reviewerId]);
                if (!$reviewerStmt->fetchColumn()) {
                    throw new RuntimeException('Choose an active program coordinator or administrator.');
                }
                $assignment = $this->pdo->prepare(
                    "SELECT 1 FROM user_unit_assignments WHERE user_id = ? AND org_unit_id = ?
                     AND active = 1 AND assignment_type IN ('coordinator','reviewer')
                     AND (cycle_id IS NULL OR cycle_id = ?) LIMIT 1"
                );
                $assignment->execute([$reviewerId, $unitId, (int)$cycle['id']]);
                if (!$assignment->fetchColumn() && !in_array($reviewerId, $this->adminIds(), true)) {
                    throw new RuntimeException('Assign that user to this program first in User access.');
                }
            }

            $current = $this->pdo->prepare(
                'SELECT * FROM cycle_allocations WHERE cycle_scholarship_id = ? AND org_unit_id = ? FOR UPDATE'
            );
            $current->execute([$fundId, $unitId]);
            $existing = $current->fetch();
            $other = $this->pdo->prepare(
                'SELECT COALESCE(SUM(authorized_new_amount),0) FROM cycle_allocations
                 WHERE cycle_scholarship_id = ? AND id != ?'
            );
            $other->execute([$fundId, $existing['id'] ?? 0]);
            if ((float)$other->fetchColumn() + (float)$amount > (float)$fundRow['total_authorized_amount'] + .005) {
                throw new RuntimeException('The allocation exceeds this scholarship’s annual available authority.');
            }
            if ($existing) {
                $committed = $this->pdo->prepare(
                    'SELECT COALESCE(SUM(recommended_amount),0) FROM recommendations WHERE cycle_allocation_id = ?'
                );
                $committed->execute([$existing['id']]);
                if ((float)$committed->fetchColumn() > (float)$amount + .005) {
                    throw new RuntimeException('Cannot reduce an allocation below its recommendations.');
                }
                $this->pdo->prepare(
                    'UPDATE cycle_allocations SET authorized_new_amount = ?, planned_new_award_count = ?,
                     primary_reviewer_user_id = ?, status = "confirmed" WHERE id = ?'
                )->execute([$amount, $count, $reviewerId ?: null, $existing['id']]);
            } else {
                $this->pdo->prepare(
                    "INSERT INTO cycle_allocations
                     (cycle_scholarship_id, org_unit_id, authorized_new_amount, planned_new_award_count,
                      primary_reviewer_user_id, status) VALUES (?, ?, ?, ?, ?, 'confirmed')"
                )->execute([$fundId, $unitId, $amount, $count, $reviewerId ?: null]);
            }

            if ($reviewerId > 0) {
                $reviewUnit = $this->pdo->prepare(
                    'SELECT id, status FROM cycle_review_units WHERE cycle_id = ? AND org_unit_id = ? FOR UPDATE'
                );
                $reviewUnit->execute([(int)$cycle['id'], $unitId]);
                $existingUnit = $reviewUnit->fetch();
                if ($existingUnit && !in_array($existingUnit['status'], ['submitted','closed','resubmitted'], true)) {
                    $this->pdo->prepare(
                        'UPDATE cycle_review_units SET primary_reviewer_user_id = ? WHERE id = ?'
                    )->execute([$reviewerId, $existingUnit['id']]);
                } elseif (!$existingUnit) {
                    $due = $cycle['program_review_due_at'] ?: date('Y-m-d H:i:s', strtotime('+60 days'));
                    $this->pdo->prepare(
                        "INSERT INTO cycle_review_units (cycle_id, org_unit_id, primary_reviewer_user_id, due_at, status)
                         VALUES (?, ?, ?, ?, 'not_started')"
                    )->execute([(int)$cycle['id'], $unitId, $reviewerId, $due]);
                }
            }
            $this->audit('cycle_wizard.assignment_saved', 'academic_cycle', (int)$cycle['id'], (int)$cycle['id']);
            $this->pdo->commit();
            Flash::success('Allocation and reviewer saved.');
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            Flash::error($e->getMessage());
        }
        $this->redirect($this->url($cycle, 3));
    }

    public function openReview(array $params): never
    {
        $this->requireAdmin();
        $this->requirePost();
        $cycle = $this->findCycle((int)($params['id'] ?? 0));
        $this->requireActive($cycle);
        try {
            if (!in_array($cycle['status'], ['setup','ready_for_applicants','review_open'], true)) {
                throw new RuntimeException('This cycle is already past the review setup stage.');
            }
            $counts = $this->counts((int)$cycle['id']);
            if ($counts['funds'] < 1 || $counts['allocations'] < 1 || $counts['missing_reviewers'] > 0) {
                throw new RuntimeException('Import awards, assign allocations, and designate a reviewer for each allocation.');
            }
            $this->pdo->beginTransaction();
            $this->pdo->prepare(
                "UPDATE academic_cycles SET status = 'review_open' WHERE id = ? AND status IN ('setup','ready_for_applicants','review_open')"
            )->execute([(int)$cycle['id']]);
            $this->pdo->prepare(
                "UPDATE cycle_allocations ca
                 JOIN cycle_scholarships cs ON cs.id = ca.cycle_scholarship_id
                 SET ca.status = 'review_open'
                 WHERE cs.cycle_id = ? AND ca.status IN ('draft','confirmed')"
            )->execute([(int)$cycle['id']]);
            $this->audit('cycle_wizard.review_opened', 'academic_cycle', (int)$cycle['id'], (int)$cycle['id']);
            $this->pdo->commit();
            Flash::success('Program review is open. Your scholarship allocations are ready for recommendations.');
            $this->redirect('/awards/available');
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            Flash::error($e->getMessage());
            $this->redirect($this->url($cycle, 6));
        }
    }

    private function yearStep(array $cycle): string
    {
        $id = (int)$cycle['id'];
        $due = $cycle['program_review_due_at'] ? date('Y-m-d\TH:i', strtotime((string)$cycle['program_review_due_at'])) : '';
        $thank = $cycle['thank_you_due_at'] ? date('Y-m-d\TH:i', strtotime((string)$cycle['thank_you_due_at'])) : '';
        $activate = (int)$cycle['is_current'] === 1 ? '<span class="status status-success">Current cycle</span>'
            : '<form method="post" action="/admin/cycles/' . $id . '/current">'
            . View::csrfField() . '<input type="hidden" name="wizard" value="1">'
            . '<button type="submit">Make this the active cycle</button></form>';
        $demoReset = '';
        if ((Env::get('APP_ENV', 'development') ?? 'development') === 'testing') {
            $preview = (new TestDataCleaner($this->pdo))->preview((string)$cycle['label']);
            $counts = $preview['counts'];
            $summary = (int)$counts['scholarship_funds'] . ' fund(s), '
                . (int)$counts['applications'] . ' application(s), '
                . (int)$counts['awards'] . ' award(s), '
                . (int)$counts['enrollment_imports'] . ' enrollment import(s)';
            $demoReset = '<details class="card" style="margin-top:1rem">'
                . '<summary>Clear demo cycle records before loading a new awards file</summary>'
                . '<div class="notice" style="margin-top:.75rem">Testing mode only. The reset removes records for this cycle, '
                . 'not departments, programs, offerings, academic terms, reviewers, user accounts, or templates. '
                . 'Previously uploaded source files remain in protected storage for retention review.</div>'
                . '<p><strong>Records to clear:</strong> ' . View::e($summary) . '.</p>'
                . '<form method="post" action="/admin/cycles/' . $id . '/wizard/reset">'
                . View::csrfField()
                . '<label for="reset_confirmation">Type DELETE ' . View::e($cycle['label']) . ' to confirm</label>'
                . '<input id="reset_confirmation" name="confirmation" autocomplete="off" required>'
                . '<div class="form-actions"><button type="submit">Clear demo records for this cycle</button></div></form>'
                . '</details>';
        }
        return '<section class="card"><h2>1. Confirm the academic year</h2>'
            . '<p>This year is <strong>' . View::e($cycle['label']) . '</strong>. '
            . 'The correct active year is important because imports and recommendations are connected to it.</p>'
            . '<div class="actions">' . $activate . '</div>'
            . '<form method="post" action="/admin/cycles/' . $id . '/dates">'
            . View::csrfField() . '<input type="hidden" name="wizard" value="1">'
            . '<div class="form-grid">'
            . '<div><label for="program_review_due_at">Faculty recommendation deadline</label>'
            . '<input type="datetime-local" id="program_review_due_at" name="program_review_due_at" value="' . View::e($due) . '"></div>'
            . '<div><label for="thank_you_due_at">Student thank-you deadline</label>'
            . '<input type="datetime-local" id="thank_you_due_at" name="thank_you_due_at" value="' . View::e($thank) . '"></div>'
            . '</div><div class="form-actions"><button type="submit">Save dates and continue</button></div></form></section>'
            . $demoReset;
    }

    private function awardsStep(array $cycle, array $counts): string
    {
        $disabled = (int)$cycle['is_current'] !== 1;
        $action = '/admin/cycles/' . (int)$cycle['id'] . '/wizard/awards';
        $current = $disabled
            ? '<div class="notice">Make this the active cycle on Step 1 before importing.</div>' : '';
        return '<section class="card"><h2>2. Upload annual award data</h2>'
            . '<p>Import the amount available for each scholarship, along with any program allocations in your file. '
            . 'You can import an updated file later without removing applicant or recipient history.</p>'
            . '<div class="metric-grid"><div class="metric-card"><div><span class="metric-label">Funds loaded</span>'
            . '<strong class="metric-value">' . $counts['funds'] . '</strong></div></div>'
            . '<div class="metric-card"><div><span class="metric-label">Authorized value</span>'
            . '<strong class="metric-value">' . View::money($counts['authority']) . '</strong></div></div></div>'
            . $current
            . '<form method="post" action="' . $action . '" enctype="multipart/form-data">'
            . View::csrfField()
            . '<label for="file_kind">Which file are you uploading?</label>'
            . '<select id="file_kind" name="file_kind">'
            . '<option value="standard">Standardized awards & allocations (CSV / Excel)</option>'
            . '<option value="fy27">Existing FY27 award authority workbook (Excel)</option></select>'
            . '<p class="muted">The standardized file includes scholarship, UICA, amount, department/program, and recruitment pool. '
            . 'The FY27 authority workbook uses the University-specific financial tabs.</p>'
            . '<label for="awards_file">Awards file</label><input type="file" id="awards_file" name="awards_file" accept=".csv,.tsv,.xls,.xlsx" required>'
            . '<div class="form-actions"><button type="submit"' . ($disabled ? ' disabled' : '') . '>Upload and continue</button></div></form>'
            . '<p><a href="/awards/allocations/template">Download the standard template</a></p></section>';
    }

    private function reviewersStep(array $cycle, array $counts): string
    {
        $cycleId = (int)$cycle['id'];
        $fundStmt = $this->pdo->prepare(
            "SELECT cs.id, s.name, cs.total_authorized_amount
             FROM cycle_scholarships cs JOIN scholarships s ON s.id = cs.scholarship_id
             WHERE cs.cycle_id = ? ORDER BY s.name"
        );
        $fundStmt->execute([$cycleId]);
        $fundOptions = '';
        foreach ($fundStmt->fetchAll() as $fund) {
            $fundOptions .= '<option value="' . (int)$fund['id'] . '">'
                . View::e($fund['name']) . ' — ' . View::money($fund['total_authorized_amount']) . '</option>';
        }

        $unitOptions = '';
        foreach ($this->pdo->query("SELECT id, unit_type, name FROM org_units
             WHERE active = 1 AND unit_type IN ('department','program','center') ORDER BY unit_type, name")->fetchAll() as $u) {
            $unitOptions .= '<option value="' . (int)$u['id'] . '">'
                . View::e(ucfirst($u['unit_type']) . ' · ' . $u['name']) . '</option>';
        }
        $userOptions = '<option value="0">Assign reviewer later</option>';
        foreach ($this->pdo->query(
            "SELECT id, display_name FROM users WHERE person_type = 'staff' AND active = 1
             AND staff_role IN ('system_admin','deans_office_admin','program_coordinator')
             ORDER BY display_name"
        )->fetchAll() as $u) {
            $userOptions .= '<option value="' . (int)$u['id'] . '">' . View::e($u['display_name']) . '</option>';
        }
        $rows = '';
        $stmt = $this->pdo->prepare(
            'SELECT ca.authorized_new_amount, ca.primary_reviewer_user_id, s.name AS scholarship_name,
             ou.name AS unit_name, u.display_name AS reviewer_name
             FROM cycle_allocations ca JOIN cycle_scholarships cs ON cs.id = ca.cycle_scholarship_id
             JOIN scholarships s ON s.id = cs.scholarship_id
             JOIN org_units ou ON ou.id = ca.org_unit_id
             LEFT JOIN users u ON u.id = ca.primary_reviewer_user_id
             WHERE cs.cycle_id = ? ORDER BY s.name, ou.name'
        );
        $stmt->execute([$cycleId]);
        foreach ($stmt->fetchAll() as $a) {
            $rows .= '<tr><td>' . View::e($a['scholarship_name']) . '</td>'
                . '<td>' . View::e($a['unit_name']) . '</td><td class="num">' . View::money($a['authorized_new_amount']) . '</td>'
                . '<td>' . View::e($a['reviewer_name'] ?: 'Not assigned') . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="4">No program allocations yet. Add one below, or import them from the standardized file.</td></tr>';
        }
        return '<section class="card"><h2>3. Confirm assignments</h2>'
            . '<p>Each allocation needs a program or department and a designated reviewer. '
            . 'If your standardized file provided these, you can review them here.</p>'
            . '<p><strong>' . $counts['allocations'] . '</strong> allocations · '
            . '<strong>' . $counts['missing_reviewers'] . '</strong> still need a reviewer</p>'
            . '<div class="table-wrap"><table><thead><tr><th>Scholarship</th><th>Assigned unit</th>'
            . '<th class="num">Amount</th><th>Reviewer</th></tr></thead><tbody>' . $rows . '</tbody></table></div>'
            . '<details style="margin-top:1rem" open><summary>Assign or update an award pool</summary>'
            . '<p class="muted">Choosing an existing scholarship/unit combination updates that allocation instead of creating another.</p>'
            . '<form method="post" action="/admin/cycles/' . $cycleId . '/wizard/assignment">'
            . View::csrfField()
            . '<div class="form-grid"><div><label for="cycle_scholarship_id">Scholarship</label>'
            . '<select id="cycle_scholarship_id" name="cycle_scholarship_id" required>' . $fundOptions . '</select></div>'
            . '<div><label for="org_unit_id">Department or program</label>'
            . '<select id="org_unit_id" name="org_unit_id" required>' . $unitOptions . '</select></div>'
            . '<div><label for="allocation_amount">Allocation amount</label>'
            . '<input id="allocation_amount" name="allocation_amount" type="number" step=".01" min=".01" required></div>'
            . '<div><label for="award_count">Number of awards (optional)</label>'
            . '<input id="award_count" name="award_count" type="number" min="1"></div>'
            . '<div><label for="reviewer_id">Primary faculty reviewer</label>'
            . '<select id="reviewer_id" name="reviewer_id">' . $userOptions . '</select></div></div>'
            . '<div class="form-actions"><button type="submit"' . (!$fundOptions ? ' disabled' : '') . '>Save assignment</button></div>'
            . '</form></details></section>';
    }

    private function applicantsStep(array $cycle, array $counts): string
    {
        $disabled = (int)$cycle['is_current'] !== 1 ? ' disabled' : '';
        return '<section class="card"><h2>4. Import scholarship applicants</h2>'
            . '<p>Upload your candidate list here. Most files need a one-time column mapping step. '
            . 'Students nominated for recruitment awards do not have to appear in the applicant file.</p>'
            . '<p><strong>' . $counts['applicants'] . '</strong> active applicants · '
            . '<strong>' . $counts['applicant_imports'] . '</strong> import batches</p>'
            . '<form method="post" action="/admin/applicants/imports" enctype="multipart/form-data">'
            . View::csrfField() . '<label for="applicant_file">Applicant export</label>'
            . '<input type="file" id="applicant_file" name="applicant_file" accept=".csv,.tsv,.txt,.xls,.xlsx" required>'
            . '<input type="hidden" name="wizard" value="1">'
            . '<div class="form-actions"><button type="submit"' . $disabled . '>Upload and map applicants</button></div></form>'
            . '<p><a href="/admin/applicants">Review all applicants</a> · '
            . '<a href="/awards/recommendations">Recruitment nominations</a></p>'
            . '<p class="muted">You can continue without applicants if this cycle uses recruitment nominations only.</p></section>';
    }

    private function eligibilityStep(array $cycle, array $counts): string
    {
        $options = '<option value="">Choose a term</option>';
        $terms = $this->pdo->prepare('SELECT id, display_name FROM academic_terms WHERE cycle_id = ? ORDER BY calendar_year, season');
        $terms->execute([(int)$cycle['id']]);
        foreach ($terms->fetchAll() as $term) {
            $options .= '<option value="' . (int)$term['id'] . '">' . View::e($term['display_name']) . '</option>';
        }
        $disabled = (int)$cycle['is_current'] !== 1 ? ' disabled' : '';
        return '<section class="card"><h2>5. Import student enrollment and eligibility</h2>'
            . '<p>Upload the MAUI enrollment snapshot for Fall and/or Spring. This validates whether a proposed '
            . 'recipient is enrolled. No award can be approved without verification.</p>'
            . '<p><strong>' . $counts['enrollment_imports'] . '</strong> successful snapshots loaded</p>'
            . '<form method="post" action="/admin/enrollment" enctype="multipart/form-data">'
            . View::csrfField()
            . '<div class="form-grid"><div><label for="academic_term_id">Academic term</label>'
            . '<select id="academic_term_id" name="academic_term_id" required>' . $options . '</select></div>'
            . '<div><label for="enrollment_file">MAUI enrollment export</label>'
            . '<input type="file" id="enrollment_file" name="enrollment_file" accept=".xls,.tsv,.txt" required></div></div>'
            . '<input type="hidden" name="wizard" value="1">'
            . '<div class="form-actions"><button type="submit"' . $disabled . '>Import enrollment</button></div></form>'
            . '<p class="muted">The supported MAUI snapshot is tab-delimited even when the file ends in .xls. '
            . 'You can upload newer snapshots as enrollment changes.</p></section>';
    }

    private function finishStep(array $cycle, array $counts): string
    {
        $ready = $counts['funds'] > 0 && $counts['allocations'] > 0
            && $counts['missing_reviewers'] === 0 && (int)$cycle['is_current'] === 1;
        $checks = [
            ['Academic year active', (int)$cycle['is_current'] === 1],
            ['Scholarship authority uploaded', $counts['funds'] > 0],
            ['At least one allocation assigned', $counts['allocations'] > 0],
            ['Reviewers assigned to all allocations', $counts['missing_reviewers'] === 0 && $counts['allocations'] > 0],
            ['Applicants loaded (optional for recruitment)', $counts['applicants'] > 0],
            ['Enrollment snapshot (required before approval)', $counts['enrollment_imports'] > 0],
        ];
        $rows = '';
        foreach ($checks as [$label, $ok]) {
            $rows .= '<div class="check-item"><strong>' . View::e($label) . '</strong>'
                . '<span class="status status-' . ($ok ? 'success' : 'warning') . '">'
                . ($ok ? 'Ready' : 'Pending') . '</span></div>';
        }
        $status = (string)$cycle['status'] === 'review_open'
            ? '<div class="success-box">This cycle is open for faculty recommendations.</div>' : '';
        return '<section class="card"><h2>6. Review and open the cycle</h2>'
            . '<p>Confirm the information and begin faculty review. You may add applicants and '
            . 'eligibility snapshots later, but scholarship allocations and reviewers must be assigned now.</p>'
            . $status . '<div class="checklist">' . $rows . '</div>'
            . '<form method="post" action="/admin/cycles/' . (int)$cycle['id'] . '/wizard/open">'
            . View::csrfField() . '<div class="form-actions"><button type="submit"'
            . ($ready ? '' : ' disabled') . '>Open faculty review</button></div></form>'
            . '<p class="muted">The Dean’s Office retains approval authority. Opening reviews never '
            . 'sends student emails or exports scholarships to MAUI.</p></section>';
    }

    private function counts(int $cycleId): array
    {
        $numbers = [];
        $queries = [
            'funds' => 'SELECT COUNT(*) FROM cycle_scholarships WHERE cycle_id = ?',
            'authority' => 'SELECT COALESCE(SUM(total_authorized_amount),0) FROM cycle_scholarships WHERE cycle_id = ?',
            'allocations' => 'SELECT COUNT(*) FROM cycle_allocations ca JOIN cycle_scholarships cs ON cs.id = ca.cycle_scholarship_id WHERE cs.cycle_id = ?',
            'missing_reviewers' => 'SELECT COUNT(*) FROM cycle_allocations ca JOIN cycle_scholarships cs ON cs.id = ca.cycle_scholarship_id WHERE cs.cycle_id = ? AND (ca.primary_reviewer_user_id IS NULL OR NOT EXISTS (SELECT 1 FROM cycle_review_units ru WHERE ru.cycle_id = cs.cycle_id AND ru.org_unit_id = ca.org_unit_id))',
            'applicants' => 'SELECT COUNT(*) FROM applications WHERE cycle_id = ? AND active = 1',
            'applicant_imports' => 'SELECT COUNT(*) FROM application_imports WHERE cycle_id = ?',
            'enrollment_imports' => "SELECT COUNT(*) FROM enrollment_imports WHERE cycle_id = ? AND status = 'completed'",
        ];
        foreach ($queries as $name => $sql) {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$cycleId]);
            $numbers[$name] = $name === 'authority' ? (float)$stmt->fetchColumn() : (int)$stmt->fetchColumn();
        }
        return $numbers;
    }

    private function findCycle(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM academic_cycles WHERE id = ?');
        $stmt->execute([$id]);
        $cycle = $stmt->fetch();
        if (!$cycle) {
            throw new RuntimeException('Academic cycle not found.');
        }
        return $cycle;
    }

    private function requireActive(array $cycle): void
    {
        if ((int)$cycle['is_current'] !== 1) {
            throw new RuntimeException('Activate this cycle before importing or changing allocations.');
        }
    }

    private function adminIds(): array
    {
        return array_map('intval', $this->pdo->query(
            "SELECT id FROM users WHERE active = 1 AND staff_role IN ('system_admin','deans_office_admin')"
        )->fetchAll(\PDO::FETCH_COLUMN));
    }

    private function url(array $cycle, int $step): string
    {
        return '/admin/cycles/' . (int)$cycle['id'] . '/setup?step=' . $step;
    }
}

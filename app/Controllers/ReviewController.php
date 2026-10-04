<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthService;
use App\Http\Csrf;
use App\Policies\ProgramScopePolicy;
use App\Services\Eligibility\EligibilityAssessmentService;
use App\Services\Eligibility\EligibilityService;
use App\Services\Recommendation\RecommendationService;
use App\Support\Flash;
use App\Support\View;
use PDO;
use RuntimeException;

final class ReviewController
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AuthService $auth,
        private readonly ProgramScopePolicy $scope
    ) {
    }

    public function index(): string
    {
        $this->requireStaff();
        $cycle = $this->requireCurrentCycle();

        $units = $this->visibleReviewUnits((int) $cycle['id']);
        $rows = '';

        foreach ($units as $unit) {
            $rows .= '<tr>'
                . '<td><a href="/review/' . (int) $unit['org_unit_id'] . '">' . View::e($unit['unit_name']) . '</a></td>'
                . '<td>' . View::e(ucfirst($unit['review_method'])) . '</td>'
                . '<td>' . View::e($unit['reviewer_name']) . '</td>'
                . '<td>' . View::e(date('M j, Y g:i a', strtotime((string) $unit['due_at']))) . '</td>'
                . '<td>' . View::status($unit['status']) . '</td>'
                . '<td><a class="button secondary small" href="/review/' . (int) $unit['org_unit_id'] . '">Open</a></td>'
                . '</tr>';
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="6">No scholarship review units are assigned to you for this cycle.</td></tr>';
        }

        $body = Flash::render()
            . '<div class="page-header"><div><h1>Scholarship review</h1><p>Review applicants and submit program recommendations.</p></div></div>'
            . '<div class="table-wrap"><table><thead><tr><th>Program / unit</th><th>Method</th><th>Primary reviewer</th><th>Deadline</th><th>Status</th><th>Action</th></tr></thead><tbody>'
            . $rows . '</tbody></table></div>';

        return $this->render('Scholarship review', $body, $cycle);
    }

    public function unit(array $params): string
    {
        $this->requireStaff();
        $cycle = $this->requireCurrentCycle();
        $orgUnitId = (int) ($params['org'] ?? 0);

        $reviewUnit = $this->reviewUnit((int) $cycle['id'], $orgUnitId);
        $this->assertScope($orgUnitId, (int) $cycle['id']);

        $canEdit = $this->canEdit($reviewUnit);
        $view = ($_GET['view'] ?? 'scholarships') === 'students' ? 'students' : 'scholarships';

        $orgStmt = $this->pdo->prepare('SELECT * FROM org_units WHERE id = ?');
        $orgStmt->execute([$orgUnitId]);
        $org = $orgStmt->fetch();

        if (!$org) {
            throw new RuntimeException('Review unit not found.');
        }

        $allocStmt = $this->pdo->prepare(
            "SELECT ca.*, cs.total_authorized_amount, s.name AS scholarship_name,
                    s.uica_account_number, siv.original_intent_text,
                    sar.amount_mode, sar.renewable
             FROM cycle_allocations ca
             JOIN cycle_scholarships cs ON cs.id = ca.cycle_scholarship_id
             JOIN scholarships s ON s.id = cs.scholarship_id
             JOIN scholarship_intent_versions siv ON siv.id = cs.intent_version_id
             LEFT JOIN scholarship_award_rules sar ON sar.intent_version_id = cs.intent_version_id
             WHERE cs.cycle_id = ? AND ca.org_unit_id = ?
             ORDER BY s.name"
        );
        $allocStmt->execute([(int) $cycle['id'], $orgUnitId]);
        $allocations = $allocStmt->fetchAll();

        $renewalStmt = $this->pdo->prepare(
            "SELECT rc.*, s.name AS scholarship_name, st.display_name AS student_name
             FROM renewal_candidates rc
             JOIN scholarships s ON s.id = rc.scholarship_id
             JOIN students st ON st.id = rc.student_id
             WHERE rc.cycle_id = ? AND rc.org_unit_id = ?
               AND rc.status <> 'declined'
             ORDER BY s.name, st.last_name, st.first_name"
        );
        $renewalStmt->execute([(int) $cycle['id'], $orgUnitId]);
        $renewals = $renewalStmt->fetchAll();

        $renewalHtml = '';
        foreach ($renewals as $renewal) {
            $amount = $renewal['proposed_current_amount'] !== null
                ? View::money($renewal['proposed_current_amount'])
                : 'Amount pending';
            $renewalHtml .= '<tr><td>' . View::e($renewal['scholarship_name']) . '</td>'
                . '<td>' . View::e($renewal['student_name']) . '</td>'
                . '<td>' . $amount . '</td><td>' . View::status($renewal['status']) . '</td></tr>';
        }
        if ($renewalHtml === '') {
            $renewalHtml = '<tr><td colspan="4">No renewable awards for this unit.</td></tr>';
        }

        $tabs = '<div class="actions" style="margin-bottom:1rem">'
            . '<a class="button ' . ($view === 'scholarships' ? '' : 'secondary') . '" href="/review/' . $orgUnitId . '?view=scholarships">Scholarship view</a>'
            . '<a class="button ' . ($view === 'students' ? '' : 'secondary') . '" href="/review/' . $orgUnitId . '?view=students">Student view</a></div>';

        $content = $view === 'students'
            ? $this->studentView($cycle, $orgUnitId, $allocations)
            : $this->scholarshipView($orgUnitId, $allocations);

        $submit = '';
        if ($canEdit) {
            $submit = '<form method="post" action="/review/' . $orgUnitId . '/submit">'
                . View::csrfField()
                . '<button type="submit">Submit program recommendations</button></form>';
        }

        $readOnly = $canEdit
            ? ''
            : '<div class="notice">This review is view-only for your account. Only the assigned primary reviewer can edit recommendations.</div>';

        $body = Flash::render()
            . '<div class="page-header"><div><h1>' . View::e($org['name']) . '</h1><p>Deadline: '
            . View::e(date('M j, Y g:i a', strtotime((string) $reviewUnit['due_at']))) . ' · '
            . View::status($reviewUnit['status']) . '</p></div>' . $submit . '</div>'
            . $readOnly
            . '<section class="card" style="margin-bottom:1rem"><h2>Renewable awards — view only</h2>'
            . '<div class="table-wrap"><table><thead><tr><th>Scholarship</th><th>Student</th><th>Current amount</th><th>Status</th></tr></thead><tbody>'
            . $renewalHtml . '</tbody></table></div></section>'
            . $tabs . $content;

        return $this->render($org['name'] . ' review', $body, $cycle);
    }

    public function allocation(array $params): string
    {
        $this->requireStaff();
        $cycle = $this->requireCurrentCycle();
        $orgUnitId = (int) ($params['org'] ?? 0);
        $allocationId = (int) ($params['allocation'] ?? 0);

        $this->assertScope($orgUnitId, (int) $cycle['id']);
        $reviewUnit = $this->reviewUnit((int) $cycle['id'], $orgUnitId);
        $canEdit = $this->canEdit($reviewUnit);

        $stmt = $this->pdo->prepare(
            "SELECT ca.*, cs.id AS cycle_scholarship_id, cs.suggested_new_award_amount AS cycle_suggested,
                    s.name AS scholarship_name, s.uica_account_number,
                    siv.original_intent_text, siv.structured_summary,
                    sar.amount_mode, sar.manual_amount_rule_text
             FROM cycle_allocations ca
             JOIN cycle_scholarships cs ON cs.id = ca.cycle_scholarship_id
             JOIN scholarships s ON s.id = cs.scholarship_id
             JOIN scholarship_intent_versions siv ON siv.id = cs.intent_version_id
             LEFT JOIN scholarship_award_rules sar ON sar.intent_version_id = cs.intent_version_id
             WHERE ca.id = ? AND ca.org_unit_id = ? AND cs.cycle_id = ?"
        );
        $stmt->execute([$allocationId, $orgUnitId, (int) $cycle['id']]);
        $allocation = $stmt->fetch();

        if (!$allocation) {
            throw new RuntimeException('Scholarship allocation not found.');
        }

        $criteriaStmt = $this->pdo->prepare(
            "SELECT sc.*
             FROM scholarship_criteria sc
             JOIN cycle_scholarships cs ON cs.intent_version_id = sc.intent_version_id
             WHERE cs.id = ?
             ORDER BY sc.sort_order, sc.id"
        );
        $criteriaStmt->execute([(int) $allocation['cycle_scholarship_id']]);
        $criteriaRows = '';

        foreach ($criteriaStmt->fetchAll() as $criterion) {
            $kind = ucfirst(str_replace('_', ' ', $criterion['criterion_kind']));
            $criteriaRows .= '<li><strong>' . View::e($kind) . ':</strong> '
                . View::e($criterion['display_requirement']) . '</li>';
        }

        if ($criteriaRows === '') {
            $criteriaRows = '<li>No structured criteria loaded.</li>';
        }

        $appStmt = $this->pdo->prepare(
            "SELECT a.*, st.display_name, st.university_id
             FROM applications a
             JOIN students st ON st.id = a.student_id
             WHERE a.cycle_id = ? AND a.org_unit_id = ? AND a.active = 1
             ORDER BY st.last_name, st.first_name"
        );
        $appStmt->execute([(int) $cycle['id'], $orgUnitId]);

        $assessmentService = new EligibilityAssessmentService($this->pdo, new EligibilityService());
        $candidateRows = '';

        foreach ($appStmt->fetchAll() as $application) {
            $assessment = $assessmentService->latestOrAssess(
                (int) $allocation['cycle_scholarship_id'],
                (int) $application['id']
            );

            $recStmt = $this->pdo->prepare(
                'SELECT * FROM recommendations WHERE cycle_allocation_id = ? AND student_id = ?'
            );
            $recStmt->execute([$allocationId, (int) $application['student_id']]);
            $recommendation = $recStmt->fetch() ?: null;

            $details = '';
            foreach ($assessment['results'] as $result) {
                $details .= '<li><strong>' . View::e($result['display_label']) . ':</strong> '
                    . View::e($result['source_value_display'] ?? 'Unknown')
                    . ' — ' . View::e(str_replace('_', ' ', $result['result'])) . '</li>';
            }
            if ($details === '') {
                $details = '<li>No automated criteria to evaluate.</li>';
            }

            $rankValue = $recommendation['rank_position'] ?? '';
            $defaultAmount = $recommendation['recommended_amount']
                ?? $allocation['suggested_award_amount']
                ?? $allocation['cycle_suggested']
                ?? '';
            $fixed = ($allocation['amount_mode'] ?? '') === 'fixed_per_award';
            $amountAttr = $fixed ? ' readonly aria-readonly="true"' : '';

            $actions = '';
            if ($canEdit) {
                $actions = '<form method="post" action="/review/' . $orgUnitId . '/allocation/' . $allocationId . '/recommend">'
                    . View::csrfField()
                    . '<input type="hidden" name="application_id" value="' . (int) $application['id'] . '">'
                    . '<input type="hidden" name="eligibility_assessment_id" value="' . (int) $assessment['id'] . '">'
                    . '<label class="muted" for="rank_' . (int) $application['id'] . '">Rank</label>'
                    . '<input id="rank_' . (int) $application['id'] . '" name="rank" type="number" min="1" value="' . View::e($rankValue) . '" required>'
                    . '<label class="muted" for="amount_' . (int) $application['id'] . '">Amount</label>'
                    . '<input id="amount_' . (int) $application['id'] . '" name="amount" type="number" min="0.01" step="0.01" value="' . View::e($defaultAmount) . '"' . $amountAttr . ' required>'
                    . '<div class="actions" style="margin-top:.4rem"><button class="small" type="submit">' . ($recommendation ? 'Update' : 'Recommend') . '</button>'
                    . ($recommendation
                        ? '<button class="secondary small" type="submit" formaction="/review/' . $orgUnitId . '/allocation/' . $allocationId . '/recommend/remove" formnovalidate>Remove</button>'
                        : '')
                    . '</div></form>';
            } elseif ($recommendation) {
                $actions = 'Rank ' . (int) $recommendation['rank_position'] . '<br>' . View::money($recommendation['recommended_amount']);
            } else {
                $actions = '—';
            }

            $rubricLink = $reviewUnit['review_method'] === 'rubric'
                ? '<a href="/review/' . $orgUnitId . '/applicants/' . (int) $application['id'] . '/rubric">Score rubric</a>'
                : '';

            $candidateRows .= '<tr>'
                . '<td><a href="/review/' . $orgUnitId . '/applicants/' . (int) $application['id'] . '"><strong>' . View::e($application['display_name']) . '</strong></a><br><span class="muted">'
                . View::e($application['university_id']) . '</span></td>'
                . '<td>' . View::e($application['gpa'] ?? '—') . '</td>'
                . '<td>' . View::status($assessment['status'])
                . '<details style="margin-top:.4rem"><summary>Why?</summary><ul class="criteria">' . $details . '</ul></details></td>'
                . '<td>' . $rubricLink . '</td>'
                . '<td style="min-width:12rem">' . $actions . '</td></tr>';
        }

        if ($candidateRows === '') {
            $candidateRows = '<tr><td colspan="5">No applicants are currently mapped to this program.</td></tr>';
        }

        $amountRule = $allocation['amount_mode']
            ? View::e(ucwords(str_replace('_', ' ', $allocation['amount_mode'])))
            : 'Not configured';

        $body = Flash::render()
            . '<div class="page-header"><div><h1>' . View::e($allocation['scholarship_name']) . '</h1><p>UICA '
            . View::e($allocation['uica_account_number']) . ' · Allocation ' . View::money($allocation['authorized_new_amount'])
            . ' · ' . $amountRule . '</p></div><a class="button secondary" href="/review/' . $orgUnitId . '">Back to program</a></div>'
            . '<section class="card"><h2>Donor intent</h2><p>' . nl2br(View::e($allocation['original_intent_text'])) . '</p>'
            . '<h3>Structured criteria</h3><ul class="criteria">' . $criteriaRows . '</ul></section>'
            . '<section class="card" style="margin-top:1rem"><h2>Applicants</h2>'
            . '<div class="table-wrap"><table><thead><tr><th>Student</th><th>GPA</th><th>Eligibility</th><th>Rubric</th><th>Recommendation</th></tr></thead><tbody>'
            . $candidateRows . '</tbody></table></div></section>';

        return $this->render($allocation['scholarship_name'], $body, $cycle);
    }

    public function recommend(array $params): never
    {
        $this->requireStaff();
        $this->requirePost();
        $cycle = $this->requireCurrentCycle();
        $orgUnitId = (int) ($params['org'] ?? 0);
        $allocationId = (int) ($params['allocation'] ?? 0);

        try {
            $reviewUnit = $this->reviewUnit((int) $cycle['id'], $orgUnitId);
            $this->assertEditable($reviewUnit);

            $applicationId = (int) ($_POST['application_id'] ?? 0);
            $assessmentId = (int) ($_POST['eligibility_assessment_id'] ?? 0);
            $rank = (int) ($_POST['rank'] ?? 0);
            $amount = round((float) ($_POST['amount'] ?? 0), 2);

            if ($applicationId < 1 || $assessmentId < 1 || $rank < 1 || $amount <= 0) {
                throw new RuntimeException('Recommendation is incomplete.');
            }

            $contextStmt = $this->pdo->prepare(
                "SELECT a.student_id, ea.status AS eligibility_status,
                        ca.cycle_scholarship_id, ca.authorized_new_amount,
                        ca.suggested_award_amount, cs.suggested_new_award_amount AS cycle_suggested,
                        sar.amount_mode
                 FROM applications a
                 JOIN eligibility_assessments ea
                   ON ea.id = ? AND ea.application_id = a.id
                 JOIN cycle_allocations ca ON ca.id = ?
                 JOIN cycle_scholarships cs ON cs.id = ca.cycle_scholarship_id
                 LEFT JOIN scholarship_award_rules sar ON sar.intent_version_id = cs.intent_version_id
                 WHERE a.id = ? AND a.cycle_id = ? AND a.org_unit_id = ?"
            );
            $contextStmt->execute([$assessmentId, $allocationId, $applicationId, (int) $cycle['id'], $orgUnitId]);
            $context = $contextStmt->fetch();

            if (!$context) {
                throw new RuntimeException('Applicant does not belong to this review unit.');
            }

            if (($context['amount_mode'] ?? '') === 'fixed_per_award') {
                $fixed = $context['suggested_award_amount'] ?? $context['cycle_suggested'];
                if ($fixed === null) {
                    throw new RuntimeException('Dean’s Office must configure the fixed award amount first.');
                }
                $amount = (float) $fixed;
            }

            $existingStmt = $this->pdo->prepare(
                'SELECT id FROM recommendations WHERE cycle_allocation_id = ? AND student_id = ?'
            );
            $existingStmt->execute([$allocationId, (int) $context['student_id']]);
            $existingId = $existingStmt->fetchColumn();

            if ($existingId !== false) {
                $stmt = $this->pdo->prepare(
                    'UPDATE recommendations
                     SET rank_position = ?, recommended_amount = ?,
                         eligibility_assessment_id = ?, eligibility_status_at_submission = ?,
                         submitted_snapshot_json = NULL, created_by_user_id = ?
                     WHERE id = ?'
                );
                $stmt->execute([
                    $rank, $amount, $assessmentId, $context['eligibility_status'],
                    $this->auth->userId(), $existingId,
                ]);
            } else {
                $stmt = $this->pdo->prepare(
                    'INSERT INTO recommendations (
                        cycle_allocation_id, student_id, rank_position, recommended_amount,
                        eligibility_assessment_id, eligibility_status_at_submission, created_by_user_id
                     ) VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $allocationId, $context['student_id'], $rank, $amount,
                    $assessmentId, $context['eligibility_status'], $this->auth->userId(),
                ]);
            }

            $this->pdo->prepare(
                "UPDATE cycle_review_units SET status = 'draft'
                 WHERE cycle_id = ? AND org_unit_id = ? AND status IN ('not_started','reopened')"
            )->execute([(int) $cycle['id'], $orgUnitId]);

            Flash::success(
                in_array($context['eligibility_status'], ['potentially_ineligible','insufficient_information'], true)
                    ? 'Recommendation saved with an eligibility warning for Dean’s Office review.'
                    : 'Recommendation saved.'
            );
        } catch (\Throwable $e) {
            Flash::error($e->getMessage());
        }

        $this->redirect('/review/' . $orgUnitId . '/allocation/' . $allocationId);
    }

    public function removeRecommendation(array $params): never
    {
        $this->requireStaff();
        $this->requirePost();
        $cycle = $this->requireCurrentCycle();
        $orgUnitId = (int) ($params['org'] ?? 0);
        $allocationId = (int) ($params['allocation'] ?? 0);

        try {
            $reviewUnit = $this->reviewUnit((int) $cycle['id'], $orgUnitId);
            $this->assertEditable($reviewUnit);

            $applicationId = (int) ($_POST['application_id'] ?? 0);
            $stmt = $this->pdo->prepare(
                'DELETE r FROM recommendations r
                 JOIN applications a ON a.student_id = r.student_id
                 WHERE r.cycle_allocation_id = ?
                   AND a.id = ?
                   AND a.cycle_id = ?
                   AND a.org_unit_id = ?'
            );
            $stmt->execute([$allocationId, $applicationId, (int) $cycle['id'], $orgUnitId]);
            Flash::success('Recommendation removed.');
        } catch (\Throwable $e) {
            Flash::error($e->getMessage());
        }

        $this->redirect('/review/' . $orgUnitId . '/allocation/' . $allocationId);
    }

    public function applicant(array $params): string
    {
        $this->requireStaff();
        $cycle = $this->requireCurrentCycle();
        $orgUnitId = (int) ($params['org'] ?? 0);
        $applicationId = (int) ($params['application'] ?? 0);

        $this->assertScope($orgUnitId, (int) $cycle['id']);

        $stmt = $this->pdo->prepare(
            "SELECT a.*, st.display_name, st.university_id, st.email,
                    ou.name AS program_name,
                    po.pgms_objective_key, po.pgms_sub_program_descr
             FROM applications a
             JOIN students st ON st.id = a.student_id
             LEFT JOIN org_units ou ON ou.id = a.org_unit_id
             LEFT JOIN program_offerings po ON po.id = a.program_offering_id
             WHERE a.id = ? AND a.cycle_id = ? AND a.org_unit_id = ?"
        );
        $stmt->execute([$applicationId, (int) $cycle['id'], $orgUnitId]);
        $application = $stmt->fetch();

        if (!$application) {
            http_response_code(404);
            throw new RuntimeException('Applicant not found in this review unit.');
        }

        $responses = $application['application_responses_json']
            ? json_decode((string) $application['application_responses_json'], true)
            : [];
        $responses = is_array($responses) ? $responses : [];

        $responseRows = '';
        foreach ($responses as $question => $answer) {
            if (is_array($answer)) {
                $answer = implode(', ', array_map('strval', $answer));
            } elseif (is_bool($answer)) {
                $answer = $answer ? 'Yes' : 'No';
            } elseif ($answer === null || trim((string) $answer) === '') {
                continue;
            }

            $responseRows .= '<tr><th scope="row">' . View::e((string) $question) . '</th><td>'
                . nl2br(View::e((string) $answer)) . '</td></tr>';
        }

        if ($responseRows === '') {
            $responseRows = '<tr><td>No additional application responses were included in the imported file.</td></tr>';
        }

        $normalized = [
            'Program' => $application['program_name'] ?? null,
            'Degree / objective' => $application['pgms_objective_key'] ?? $application['degree_objective'] ?? null,
            'Subprogram / track' => $application['pgms_sub_program_descr'] ?? null,
            'Classification' => $application['classification'] ?? null,
            'GPA' => $application['gpa'] ?? null,
            'Residency state' => $application['residency_state'] ?? null,
            'Residency county' => $application['residency_county'] ?? null,
            'Citizenship country' => $application['citizenship_country'] ?? null,
            'First generation' => $application['first_generation'] === null
                ? null
                : ((int) $application['first_generation'] === 1 ? 'Yes' : 'No'),
            'Financial need' => $application['financial_need'] === null
                ? null
                : ((int) $application['financial_need'] === 1 ? 'Yes' : 'No'),
        ];

        $normalizedRows = '';
        foreach ($normalized as $label => $value) {
            $normalizedRows .= '<tr><th scope="row">' . View::e($label) . '</th><td>'
                . View::e($value ?? '—') . '</td></tr>';
        }

        $body = Flash::render()
            . '<div class="page-header"><div><h1>' . View::e($application['display_name']) . '</h1>'
            . '<p>' . View::e($application['university_id']) . ' · ' . View::e($application['email']) . '</p></div>'
            . '<a class="button secondary" href="/review/' . $orgUnitId . '?view=students">Back to program</a></div>'
            . '<section class="card"><h2>Applicant information</h2><div class="table-wrap"><table><tbody>'
            . $normalizedRows . '</tbody></table></div></section>'
            . '<section class="card" style="margin-top:1rem"><h2>Application responses</h2>'
            . '<div class="table-wrap"><table><tbody>' . $responseRows . '</tbody></table></div></section>';

        return $this->render($application['display_name'], $body, $cycle);
    }

    public function rubric(array $params): string
    {
        $this->requireStaff();
        $cycle = $this->requireCurrentCycle();
        $orgUnitId = (int) ($params['org'] ?? 0);
        $applicationId = (int) ($params['application'] ?? 0);

        $this->assertScope($orgUnitId, (int) $cycle['id']);
        $reviewUnit = $this->reviewUnit((int) $cycle['id'], $orgUnitId);
        $canEdit = $this->canEdit($reviewUnit);

        $rubricStmt = $this->pdo->prepare(
            "SELECT r.*, a.student_id, st.display_name
             FROM rubrics r
             JOIN applications a ON a.id = ? AND a.org_unit_id = r.org_unit_id AND a.cycle_id = r.cycle_id
             JOIN students st ON st.id = a.student_id
             WHERE r.cycle_id = ? AND r.org_unit_id = ?"
        );
        $rubricStmt->execute([$applicationId, (int) $cycle['id'], $orgUnitId]);
        $rubric = $rubricStmt->fetch();

        if (!$rubric) {
            throw new RuntimeException('No rubric is configured for this review unit.');
        }

        $scoreStmt = $this->pdo->prepare(
            'SELECT * FROM rubric_scores WHERE rubric_id = ? AND student_id = ? AND reviewer_user_id = ?'
        );
        $scoreStmt->execute([(int) $rubric['id'], (int) $rubric['student_id'], (int) $reviewUnit['primary_reviewer_user_id']]);
        $score = $scoreStmt->fetch() ?: null;

        $itemStmt = $this->pdo->prepare(
            'SELECT ri.*, rsi.points
             FROM rubric_items ri
             LEFT JOIN rubric_score_items rsi
               ON rsi.rubric_item_id = ri.id AND rsi.rubric_score_id = ?
             WHERE ri.rubric_id = ?
             ORDER BY ri.sort_order, ri.id'
        );
        $itemStmt->execute([$score['id'] ?? 0, (int) $rubric['id']]);

        $items = '';
        foreach ($itemStmt->fetchAll() as $item) {
            $field = $canEdit
                ? '<input name="points[' . (int) $item['id'] . ']" type="number" min="0" max="' . View::e($item['max_points']) . '" step="0.25" value="' . View::e($item['points']) . '" required>'
                : View::e($item['points'] ?? '—');

            $items .= '<tr><td><strong>' . View::e($item['label']) . '</strong><br><span class="muted">'
                . View::e($item['description'] ?? '') . '</span></td><td>' . View::e($item['max_points']) . '</td><td>' . $field . '</td></tr>';
        }

        $formStart = $canEdit
            ? '<form method="post" action="/review/' . $orgUnitId . '/applicants/' . $applicationId . '/rubric">' . View::csrfField()
            : '';
        $formEnd = $canEdit
            ? '<div class="form-actions"><button type="submit">Save rubric score</button></div></form>'
            : '';

        $body = Flash::render()
            . '<div class="page-header"><div><h1>' . View::e($rubric['display_name']) . '</h1><p>'
            . View::e($rubric['name']) . '</p></div><a class="button secondary" href="/review/' . $orgUnitId . '">Back to program</a></div>'
            . $formStart . '<div class="table-wrap"><table><thead><tr><th>Criterion</th><th>Max</th><th>Score</th></tr></thead><tbody>'
            . $items . '</tbody></table></div>' . $formEnd;

        return $this->render('Rubric score', $body, $cycle);
    }

    public function saveRubric(array $params): never
    {
        $this->requireStaff();
        $this->requirePost();
        $cycle = $this->requireCurrentCycle();
        $orgUnitId = (int) ($params['org'] ?? 0);
        $applicationId = (int) ($params['application'] ?? 0);

        try {
            $reviewUnit = $this->reviewUnit((int) $cycle['id'], $orgUnitId);
            $this->assertEditable($reviewUnit);

            $rubricStmt = $this->pdo->prepare(
                "SELECT r.id AS rubric_id, a.student_id
                 FROM rubrics r
                 JOIN applications a ON a.id = ? AND a.org_unit_id = r.org_unit_id AND a.cycle_id = r.cycle_id
                 WHERE r.cycle_id = ? AND r.org_unit_id = ?"
            );
            $rubricStmt->execute([$applicationId, (int) $cycle['id'], $orgUnitId]);
            $rubric = $rubricStmt->fetch();

            if (!$rubric) {
                throw new RuntimeException('Rubric is not configured.');
            }

            $itemsStmt = $this->pdo->prepare('SELECT id, max_points FROM rubric_items WHERE rubric_id = ?');
            $itemsStmt->execute([(int) $rubric['rubric_id']]);
            $items = $itemsStmt->fetchAll();
            $posted = $_POST['points'] ?? [];

            if (!is_array($posted) || $items === []) {
                throw new RuntimeException('Rubric scores are incomplete.');
            }

            $total = 0.0;
            foreach ($items as $item) {
                $points = isset($posted[$item['id']]) ? (float) $posted[$item['id']] : null;
                if ($points === null || $points < 0 || $points > (float) $item['max_points']) {
                    throw new RuntimeException('Each rubric score must be between zero and its maximum points.');
                }
                $total += $points;
            }

            $this->pdo->beginTransaction();

            $scoreStmt = $this->pdo->prepare(
                'SELECT id FROM rubric_scores WHERE rubric_id = ? AND student_id = ? AND reviewer_user_id = ?'
            );
            $scoreStmt->execute([
                $rubric['rubric_id'],
                $rubric['student_id'],
                $reviewUnit['primary_reviewer_user_id'],
            ]);
            $scoreId = $scoreStmt->fetchColumn();

            if ($scoreId === false) {
                $insert = $this->pdo->prepare(
                    'INSERT INTO rubric_scores (rubric_id, student_id, reviewer_user_id, total_points, completed_at)
                     VALUES (?, ?, ?, ?, NOW())'
                );
                $insert->execute([
                    $rubric['rubric_id'],
                    $rubric['student_id'],
                    $reviewUnit['primary_reviewer_user_id'],
                    $total,
                ]);
                $scoreId = (int) $this->pdo->lastInsertId();
            } else {
                $this->pdo->prepare(
                    'UPDATE rubric_scores SET total_points = ?, completed_at = NOW() WHERE id = ?'
                )->execute([$total, $scoreId]);
            }

            $upsert = $this->pdo->prepare(
                'INSERT INTO rubric_score_items (rubric_score_id, rubric_item_id, points)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE points = VALUES(points)'
            );

            foreach ($items as $item) {
                $upsert->execute([$scoreId, $item['id'], (float) $posted[$item['id']]]);
            }

            $this->pdo->commit();
            Flash::success('Rubric score saved.');
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            Flash::error($e->getMessage());
        }

        $this->redirect('/review/' . $orgUnitId . '/applicants/' . $applicationId . '/rubric');
    }

    public function submit(array $params): never
    {
        $this->requireStaff();
        $this->requirePost();
        $cycle = $this->requireCurrentCycle();
        $orgUnitId = (int) ($params['org'] ?? 0);

        try {
            $reviewUnit = $this->reviewUnit((int) $cycle['id'], $orgUnitId);
            $this->assertEditable($reviewUnit);

            $allocStmt = $this->pdo->prepare(
                "SELECT ca.id
                 FROM cycle_allocations ca
                 JOIN cycle_scholarships cs ON cs.id = ca.cycle_scholarship_id
                 WHERE cs.cycle_id = ? AND ca.org_unit_id = ?"
            );
            $allocStmt->execute([(int) $cycle['id'], $orgUnitId]);
            $allocationIds = $allocStmt->fetchAll(PDO::FETCH_COLUMN);

            if ($allocationIds === []) {
                throw new RuntimeException('This review unit has no scholarship allocations.');
            }

            $recommendationService = new RecommendationService($this->pdo);
            foreach ($allocationIds as $allocationId) {
                $recommendationService->validateAllocationRecommendations((int) $allocationId);
            }

            if ($reviewUnit['review_method'] === 'rubric') {
                $missingStmt = $this->pdo->prepare(
                    "SELECT COUNT(*)
                     FROM recommendations r
                     JOIN cycle_allocations ca ON ca.id = r.cycle_allocation_id
                     LEFT JOIN rubrics rb ON rb.cycle_id = ? AND rb.org_unit_id = ?
                     LEFT JOIN rubric_scores rs
                       ON rs.rubric_id = rb.id
                      AND rs.student_id = r.student_id
                      AND rs.reviewer_user_id = ?
                      AND rs.completed_at IS NOT NULL
                     WHERE ca.org_unit_id = ?
                       AND rs.id IS NULL"
                );
                $missingStmt->execute([
                    (int) $cycle['id'],
                    $orgUnitId,
                    (int) $reviewUnit['primary_reviewer_user_id'],
                    $orgUnitId,
                ]);

                if ((int) $missingStmt->fetchColumn() > 0) {
                    throw new RuntimeException('Complete the rubric for each recommended student before submitting.');
                }
            }

            $this->pdo->beginTransaction();

            $snapshotStmt = $this->pdo->prepare(
                "UPDATE recommendations r
                 JOIN cycle_allocations ca ON ca.id = r.cycle_allocation_id
                 SET r.submitted_snapshot_json = JSON_OBJECT(
                     'rank', r.rank_position,
                     'amount', r.recommended_amount,
                     'eligibility_status', r.eligibility_status_at_submission,
                     'submitted_at', NOW()
                 )
                 WHERE ca.org_unit_id = ?"
            );
            $snapshotStmt->execute([$orgUnitId]);

            $this->pdo->prepare(
                "UPDATE cycle_review_units
                 SET status = CASE WHEN status = 'reopened' THEN 'resubmitted' ELSE 'submitted' END,
                     submitted_at = NOW()
                 WHERE cycle_id = ? AND org_unit_id = ?"
            )->execute([(int) $cycle['id'], $orgUnitId]);

            $this->pdo->prepare(
                "UPDATE cycle_allocations ca
                 JOIN cycle_scholarships cs ON cs.id = ca.cycle_scholarship_id
                 SET ca.status = 'submitted'
                 WHERE cs.cycle_id = ? AND ca.org_unit_id = ?"
            )->execute([(int) $cycle['id'], $orgUnitId]);

            $this->pdo->commit();
            Flash::success('Program recommendations submitted to the Dean’s Office.');
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            Flash::error($e->getMessage());
        }

        $this->redirect('/review/' . $orgUnitId);
    }

    private function scholarshipView(int $orgUnitId, array $allocations): string
    {
        $rows = '';

        foreach ($allocations as $allocation) {
            $recStmt = $this->pdo->prepare(
                'SELECT COUNT(*) AS count, COALESCE(SUM(recommended_amount),0) AS total
                 FROM recommendations WHERE cycle_allocation_id = ?'
            );
            $recStmt->execute([(int) $allocation['id']]);
            $summary = $recStmt->fetch();

            $rows .= '<tr>'
                . '<td><a href="/review/' . $orgUnitId . '/allocation/' . (int) $allocation['id'] . '"><strong>'
                . View::e($allocation['scholarship_name']) . '</strong></a><br><span class="muted">UICA '
                . View::e($allocation['uica_account_number']) . '</span></td>'
                . '<td class="num">' . View::money($allocation['authorized_new_amount']) . '</td>'
                . '<td>' . (int) $summary['count'] . '</td>'
                . '<td class="num">' . View::money($summary['total']) . '</td>'
                . '<td>' . View::status($allocation['status']) . '</td>'
                . '<td><a class="button secondary small" href="/review/' . $orgUnitId . '/allocation/' . (int) $allocation['id'] . '">Review applicants</a></td>'
                . '</tr>';
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="6">No scholarship allocations assigned to this unit.</td></tr>';
        }

        return '<section class="card"><h2>Scholarships</h2><div class="table-wrap"><table>'
            . '<thead><tr><th>Scholarship</th><th class="num">Allocation</th><th>Recommended</th><th class="num">Recommended total</th><th>Status</th><th>Action</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table></div></section>';
    }

    private function studentView(array $cycle, int $orgUnitId, array $allocations): string
    {
        $stmt = $this->pdo->prepare(
            "SELECT a.*, st.display_name, st.university_id
             FROM applications a
             JOIN students st ON st.id = a.student_id
             WHERE a.cycle_id = ? AND a.org_unit_id = ? AND a.active = 1
             ORDER BY st.last_name, st.first_name"
        );
        $stmt->execute([(int) $cycle['id'], $orgUnitId]);

        $assessmentService = new EligibilityAssessmentService($this->pdo, new EligibilityService());
        $rows = '';

        foreach ($stmt->fetchAll() as $application) {
            $eligible = 0;
            $warning = 0;
            $links = [];

            foreach ($allocations as $allocation) {
                $assessment = $assessmentService->latestOrAssess(
                    (int) $allocation['cycle_scholarship_id'],
                    (int) $application['id']
                );

                if (in_array($assessment['status'], ['eligible','eligible_unmet_preference'], true)) {
                    $eligible++;
                } else {
                    $warning++;
                }

                $links[] = '<a href="/review/' . $orgUnitId . '/allocation/' . (int) $allocation['id'] . '">'
                    . View::e($allocation['scholarship_name']) . '</a> ' . View::status($assessment['status']);
            }

            $rows .= '<tr><td><a href="/review/' . $orgUnitId . '/applicants/' . (int) $application['id'] . '"><strong>' . View::e($application['display_name']) . '</strong></a><br><span class="muted">'
                . View::e($application['university_id']) . '</span></td>'
                . '<td>' . View::e($application['classification'] ?? '—') . '</td>'
                . '<td>' . View::e($application['gpa'] ?? '—') . '</td>'
                . '<td>' . $eligible . ' eligible · ' . $warning . ' warning</td>'
                . '<td>' . implode('<br>', $links) . '</td></tr>';
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="5">No applicants mapped to this program.</td></tr>';
        }

        return '<section class="card"><h2>Applicants</h2><div class="table-wrap"><table>'
            . '<thead><tr><th>Student</th><th>Classification</th><th>GPA</th><th>Scholarship summary</th><th>Scholarships</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table></div></section>';
    }

    private function visibleReviewUnits(int $cycleId): array
    {
        $role = $this->auth->staffRole();
        $userId = (int) $this->auth->userId();

        if (in_array($role, ['system_admin','deans_office_admin'], true)) {
            $stmt = $this->pdo->prepare(
                "SELECT ru.*, ou.name AS unit_name, u.display_name AS reviewer_name
                 FROM cycle_review_units ru
                 JOIN org_units ou ON ou.id = ru.org_unit_id
                 JOIN users u ON u.id = ru.primary_reviewer_user_id
                 WHERE ru.cycle_id = ? ORDER BY ou.name"
            );
            $stmt->execute([$cycleId]);
            return $stmt->fetchAll();
        }

        if ($role === 'department_chair') {
            $stmt = $this->pdo->prepare(
                "SELECT DISTINCT ru.*, ou.name AS unit_name, u.display_name AS reviewer_name
                 FROM cycle_review_units ru
                 JOIN org_units ou ON ou.id = ru.org_unit_id
                 JOIN users u ON u.id = ru.primary_reviewer_user_id
                 JOIN user_unit_assignments ua
                   ON ua.user_id = ?
                  AND ua.assignment_type = 'chair'
                  AND ua.active = 1
                  AND (ua.cycle_id IS NULL OR ua.cycle_id = ?)
                  AND (ua.org_unit_id = ou.id OR ua.org_unit_id = ou.parent_id)
                 WHERE ru.cycle_id = ?
                 ORDER BY ou.name"
            );
            $stmt->execute([$userId, $cycleId, $cycleId]);
            return $stmt->fetchAll();
        }

        $stmt = $this->pdo->prepare(
            "SELECT ru.*, ou.name AS unit_name, u.display_name AS reviewer_name
             FROM cycle_review_units ru
             JOIN org_units ou ON ou.id = ru.org_unit_id
             JOIN users u ON u.id = ru.primary_reviewer_user_id
             WHERE ru.cycle_id = ?
               AND (
                   ru.primary_reviewer_user_id = ?
                   OR EXISTS (
                       SELECT 1 FROM user_unit_assignments ua
                       WHERE ua.user_id = ?
                         AND ua.org_unit_id = ru.org_unit_id
                         AND ua.active = 1
                         AND ua.assignment_type IN ('coordinator','reviewer')
                         AND (ua.cycle_id IS NULL OR ua.cycle_id = ?)
                   )
               )
             ORDER BY ou.name"
        );
        $stmt->execute([$cycleId, $userId, $userId, $cycleId]);

        return $stmt->fetchAll();
    }

    private function reviewUnit(int $cycleId, int $orgUnitId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM cycle_review_units WHERE cycle_id = ? AND org_unit_id = ?'
        );
        $stmt->execute([$cycleId, $orgUnitId]);
        $reviewUnit = $stmt->fetch();

        if (!$reviewUnit) {
            throw new RuntimeException('Scholarship review has not been configured for this program.');
        }

        return $reviewUnit;
    }

    private function assertScope(int $orgUnitId, int $cycleId): void
    {
        if (!$this->scope->canViewOrgUnit(
            (int) $this->auth->userId(),
            $this->auth->staffRole(),
            $orgUnitId,
            $cycleId
        )) {
            http_response_code(403);
            throw new RuntimeException('You do not have access to this scholarship review unit.');
        }
    }

    private function canEdit(array $reviewUnit): bool
    {
        $editableStatus = in_array(
            $reviewUnit['status'],
            ['not_started','draft','reopened'],
            true
        );

        return $editableStatus
            && (int) $reviewUnit['primary_reviewer_user_id'] === (int) $this->auth->userId();
    }

    private function assertEditable(array $reviewUnit): void
    {
        if (!$this->canEdit($reviewUnit)) {
            throw new RuntimeException('This scholarship review is currently view-only.');
        }
    }

    private function requireStaff(): void
    {
        if (!$this->auth->check() || $this->auth->personType() !== 'staff') {
            header('Location: /login');
            exit;
        }
    }

    private function requirePost(): void
    {
        Csrf::assertValid($_POST['_csrf'] ?? null);
    }

    private function requireCurrentCycle(): array
    {
        $cycle = $this->pdo->query(
            'SELECT * FROM academic_cycles WHERE is_current = 1 LIMIT 1'
        )->fetch();

        if (!$cycle) {
            throw new RuntimeException('No current academic cycle is configured.');
        }

        return $cycle;
    }

    private function render(string $title, string $body, array $cycle): string
    {
        return View::layout(
            $title,
            $body,
            $this->auth->displayName(),
            $this->auth->staffRole(),
            $cycle['label']
        );
    }

    private function redirect(string $path): never
    {
        header('Location: ' . $path);
        exit;
    }
}

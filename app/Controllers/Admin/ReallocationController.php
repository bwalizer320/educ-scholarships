<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\Awards\AwardService;
use App\Services\Awards\DistributionService;
use App\Services\Eligibility\EligibilityAssessmentService;
use App\Services\Eligibility\EligibilityService;
use App\Support\Flash;
use App\Support\View;
use RuntimeException;

final class ReallocationController extends BaseAdminController
{
    public function index(): string
    {
        $this->requireAdmin();
        $cycle = $this->requireCurrentCycle();

        $stmt = $this->pdo->prepare(
            "SELECT cs.id, s.name, s.uica_account_number, cs.total_authorized_amount,
                    COALESCE((
                        SELECT SUM(a.total_amount)
                        FROM awards a
                        WHERE a.cycle_scholarship_id = cs.id
                          AND a.status <> 'cancelled'
                    ), 0) AS awarded_total
             FROM cycle_scholarships cs
             JOIN scholarships s ON s.id = cs.scholarship_id
             WHERE cs.cycle_id = ?
             ORDER BY s.name"
        );
        $stmt->execute([(int) $cycle['id']]);

        $rows = '';
        foreach ($stmt->fetchAll() as $row) {
            $remaining = max(0, (float) $row['total_authorized_amount'] - (float) $row['awarded_total']);

            if ($remaining <= 0.005) {
                continue;
            }

            $rows .= '<tr>'
                . '<td><strong>' . View::e($row['name']) . '</strong><br><span class="muted">UICA ' . View::e($row['uica_account_number']) . '</span></td>'
                . '<td class="num">' . View::money($row['total_authorized_amount']) . '</td>'
                . '<td class="num">' . View::money($row['awarded_total']) . '</td>'
                . '<td class="num"><strong>' . View::money($remaining) . '</strong></td>'
                . '<td><a class="button small" href="/dean/reallocation/' . (int) $row['id'] . '">Reallocate</a></td>'
                . '</tr>';
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="5">No unawarded scholarship dollars remain in the current cycle.</td></tr>';
        }

        $body = '<div class="page-header"><div><h1>Dean’s Office reallocation</h1>'
            . '<p>After program review, remaining scholarship dollars return to Dean’s Office control. This view uses actual non-cancelled awards—not original program allocations—to determine what is still available.</p></div></div>'
            . '<div class="table-wrap"><table><thead><tr><th>Scholarship</th><th class="num">Authorized</th><th class="num">Awarded</th><th class="num">Remaining</th><th>Action</th></tr></thead><tbody>'
            . $rows . '</tbody></table></div>';

        return $this->render('Dean reallocation', Flash::render() . $body, $cycle);
    }

    public function scholarship(array $params): string
    {
        $this->requireAdmin();
        $cycle = $this->requireCurrentCycle();
        $cycleScholarshipId = (int) ($params['id'] ?? 0);

        $planStmt = $this->pdo->prepare(
            "SELECT cs.*, s.name, s.uica_account_number, siv.original_intent_text
             FROM cycle_scholarships cs
             JOIN scholarships s ON s.id = cs.scholarship_id
             JOIN scholarship_intent_versions siv ON siv.id = cs.intent_version_id
             WHERE cs.id = ? AND cs.cycle_id = ?"
        );
        $planStmt->execute([$cycleScholarshipId, (int) $cycle['id']]);
        $plan = $planStmt->fetch();

        if (!$plan) {
            throw new RuntimeException('Scholarship plan not found.');
        }

        $awardedStmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(total_amount),0)
             FROM awards
             WHERE cycle_scholarship_id = ? AND status <> 'cancelled'"
        );
        $awardedStmt->execute([$cycleScholarshipId]);
        $awarded = (float) $awardedStmt->fetchColumn();
        $remaining = max(0, (float) $plan['total_authorized_amount'] - $awarded);

        $appStmt = $this->pdo->prepare(
            "SELECT a.id, a.student_id, st.display_name, st.university_id,
                    ou.name AS program_name, a.gpa
             FROM applications a
             JOIN students st ON st.id = a.student_id
             LEFT JOIN org_units ou ON ou.id = a.org_unit_id
             WHERE a.cycle_id = ? AND a.active = 1
               AND NOT EXISTS (
                   SELECT 1 FROM awards aw
                   WHERE aw.cycle_scholarship_id = ?
                     AND aw.student_id = a.student_id
                     AND aw.status <> 'cancelled'
               )
             ORDER BY st.last_name, st.first_name"
        );
        $appStmt->execute([(int) $cycle['id'], $cycleScholarshipId]);

        $assessmentService = new EligibilityAssessmentService($this->pdo, new EligibilityService());
        $rows = '';

        foreach ($appStmt->fetchAll() as $app) {
            $assessment = $assessmentService->latestOrAssess($cycleScholarshipId, (int) $app['id']);
            $rows .= '<tr>'
                . '<td><strong>' . View::e($app['display_name']) . '</strong><br><span class="muted">' . View::e($app['university_id']) . '</span></td>'
                . '<td>' . View::e($app['program_name'] ?? '—') . '</td>'
                . '<td>' . View::e($app['gpa'] ?? '—') . '</td>'
                . '<td>' . View::status($assessment['status']) . '</td>'
                . '<td><form method="post" action="/dean/reallocation/' . $cycleScholarshipId . '/award">'
                . View::csrfField()
                . '<input type="hidden" name="application_id" value="' . (int) $app['id'] . '">'
                . '<input type="hidden" name="eligibility_assessment_id" value="' . (int) $assessment['id'] . '">'
                . '<label class="muted" for="amount_' . (int) $app['id'] . '">Award amount</label>'
                . '<input id="amount_' . (int) $app['id'] . '" name="amount" type="number" min="0.01" max="' . View::e($remaining) . '" step="0.01" required>'
                . '<button class="small" type="submit" style="margin-top:.4rem">Create award</button></form></td></tr>';
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="5">No additional applicants are available for this scholarship.</td></tr>';
        }

        $body = '<div class="page-header"><div><h1>' . View::e($plan['name']) . '</h1>'
            . '<p>UICA ' . View::e($plan['uica_account_number']) . ' · Remaining ' . View::money($remaining) . '</p></div>'
            . '<a class="button secondary" href="/dean/reallocation">Back</a></div>'
            . '<section class="card"><h2>Donor intent</h2><p>' . nl2br(View::e($plan['original_intent_text'])) . '</p></section>'
            . '<section class="card" style="margin-top:1rem"><h2>Eligible applicant pool</h2>'
            . '<div class="table-wrap"><table><thead><tr><th>Student</th><th>Program</th><th>GPA</th><th>Eligibility</th><th>Create Dean’s Office award</th></tr></thead><tbody>'
            . $rows . '</tbody></table></div></section>';

        return $this->render('Reallocate ' . $plan['name'], Flash::render() . $body, $cycle);
    }

    public function award(array $params): never
    {
        $this->requireAdmin();
        $this->requirePost();
        $cycle = $this->requireCurrentCycle();
        $cycleScholarshipId = (int) ($params['id'] ?? 0);

        try {
            $applicationId = (int) ($_POST['application_id'] ?? 0);
            $assessmentId = (int) ($_POST['eligibility_assessment_id'] ?? 0);
            $amount = round((float) ($_POST['amount'] ?? 0), 2);

            $ctxStmt = $this->pdo->prepare(
                "SELECT a.student_id, ea.status AS eligibility_status,
                        cs.total_authorized_amount, sar.student_teaching_required,
                        a.student_teaching_term_id
                 FROM applications a
                 JOIN cycle_scholarships cs ON cs.id = ? AND cs.cycle_id = a.cycle_id
                 JOIN eligibility_assessments ea
                   ON ea.id = ? AND ea.application_id = a.id
                 LEFT JOIN scholarship_award_rules sar ON sar.intent_version_id = cs.intent_version_id
                 WHERE a.id = ? AND a.cycle_id = ?"
            );
            $ctxStmt->execute([
                $cycleScholarshipId,
                $assessmentId,
                $applicationId,
                (int) $cycle['id'],
            ]);
            $ctx = $ctxStmt->fetch();

            if (!$ctx || $amount <= 0) {
                throw new RuntimeException('Dean’s Office award details are invalid.');
            }

            $awardedStmt = $this->pdo->prepare(
                "SELECT COALESCE(SUM(total_amount),0)
                 FROM awards
                 WHERE cycle_scholarship_id = ? AND status <> 'cancelled'"
            );
            $awardedStmt->execute([$cycleScholarshipId]);
            $awarded = (float) $awardedStmt->fetchColumn();
            $remaining = (float) $ctx['total_authorized_amount'] - $awarded;

            if ($amount > $remaining + 0.005) {
                throw new RuntimeException('The award exceeds the remaining scholarship balance.');
            }

            $service = new AwardService($this->pdo, new DistributionService());
            $awardId = $service->createDeanReallocation(
                (int) $cycle['id'],
                (int) $ctx['student_id'],
                $cycleScholarshipId,
                $amount,
                (string) $ctx['eligibility_status']
            );

            $terms = $this->cycleTerms((int) $cycle['id']);
            $studentTeaching = (bool) ($ctx['student_teaching_required'] ?? false);
            $studentTeachingTermId = $ctx['student_teaching_term_id']
                ? (int) $ctx['student_teaching_term_id']
                : null;

            $distributions = (new DistributionService())->defaultDistributions(
                $amount,
                'academic_year',
                $terms['fall'],
                $terms['spring'],
                $studentTeaching,
                $studentTeachingTermId
            );

            if ($distributions !== []) {
                $service->replaceDistributions($awardId, $distributions);
            }

            $this->audit(
                'award.created_from_deans_reallocation',
                'award',
                $awardId,
                (int) $cycle['id'],
                null,
                ['application_id'=>$applicationId,'amount'=>$amount]
            );

            Flash::success('Dean’s Office award created and moved to enrollment verification.');
        } catch (\Throwable $e) {
            Flash::error($e->getMessage());
        }

        $this->redirect('/dean/reallocation/' . $cycleScholarshipId);
    }

    private function cycleTerms(int $cycleId): array
    {
        $stmt = $this->pdo->prepare('SELECT id, season FROM academic_terms WHERE cycle_id = ?');
        $stmt->execute([$cycleId]);
        $terms = [];

        foreach ($stmt->fetchAll() as $term) {
            $terms[$term['season']] = (int) $term['id'];
        }

        if (!isset($terms['fall'], $terms['spring'])) {
            throw new RuntimeException('Fall and Spring terms must exist before creating awards.');
        }

        return $terms;
    }
}

<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\Awards\AwardService;
use App\Services\Awards\DistributionService;
use App\Services\Enrollment\EnrollmentService;
use App\Support\Flash;
use App\Support\View;
use RuntimeException;

final class DeanReviewController extends BaseAdminController
{
    public function recommendations(): string
    {
        $this->requireAdmin();
        $cycle = $this->requireCurrentCycle();

        $stmt = $this->pdo->prepare(
            "SELECT
                r.id AS recommendation_id,
                r.rank_position,
                r.recommended_amount,
                r.eligibility_status_at_submission,
                st.display_name AS student_name,
                st.university_id,
                s.name AS scholarship_name,
                ou.name AS unit_name,
                ru.status AS review_status,
                a.id AS existing_award_id,
                a.status AS award_status
             FROM recommendations r
             JOIN cycle_allocations ca ON ca.id = r.cycle_allocation_id
             JOIN cycle_scholarships cs ON cs.id = ca.cycle_scholarship_id
             JOIN scholarships s ON s.id = cs.scholarship_id
             JOIN students st ON st.id = r.student_id
             JOIN org_units ou ON ou.id = ca.org_unit_id
             LEFT JOIN cycle_review_units ru
               ON ru.cycle_id = cs.cycle_id
              AND ru.org_unit_id = ca.org_unit_id
             LEFT JOIN awards a ON a.recommendation_id = r.id
             WHERE cs.cycle_id = ?
               AND ru.status IN ('submitted','resubmitted','closed')
             ORDER BY s.name, ou.name, r.rank_position"
        );
        $stmt->execute([(int) $cycle['id']]);

        $rows = '';
        foreach ($stmt->fetchAll() as $row) {
            $warning = $row['eligibility_status_at_submission']
                ? View::status($row['eligibility_status_at_submission'])
                : View::status('insufficient_information');

            $action = $row['existing_award_id']
                ? '<a class="button secondary small" href="/dean/verification">Award ' . View::e($row['award_status']) . '</a>'
                : '<form method="post" action="/dean/recommendations/' . (int) $row['recommendation_id'] . '/award">'
                    . View::csrfField()
                    . '<button class="small" type="submit">Move to verification</button></form>';

            $rows .= '<tr>'
                . '<td>' . View::e($row['scholarship_name']) . '</td>'
                . '<td>' . View::e($row['unit_name']) . '</td>'
                . '<td><strong>' . View::e($row['student_name']) . '</strong><br><span class="muted">'
                . View::e($row['university_id']) . '</span></td>'
                . '<td>' . (int) $row['rank_position'] . '</td>'
                . '<td class="num">' . View::money($row['recommended_amount']) . '</td>'
                . '<td>' . $warning . '</td>'
                . '<td>' . $action . '</td>'
                . '</tr>';
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="7">No submitted program recommendations are awaiting Dean’s Office review.</td></tr>';
        }

        $body = <<<HTML
<div class="page-header">
    <div>
        <h1>Dean’s Office recommendation review</h1>
        <p>Program recommendations are advisory. Eligibility warnings remain visible, but the Dean’s Office makes the final award decision.</p>
    </div>
    <a class="button secondary" href="/dean/verification">Enrollment verification</a>
</div>

<div class="table-wrap">
<table>
<thead><tr><th>Scholarship</th><th>Program</th><th>Student</th><th>Rank</th><th class="num">Amount</th><th>Eligibility</th><th>Action</th></tr></thead>
<tbody>{$rows}</tbody>
</table>
</div>
HTML;

        return $this->render('Dean recommendation review', $body, $cycle);
    }

    public function createAward(array $params): never
    {
        $this->requireAdmin();
        $this->requirePost();
        $cycle = $this->requireCurrentCycle();
        $recommendationId = (int) ($params['id'] ?? 0);

        try {
            $stmt = $this->pdo->prepare(
                "SELECT
                    r.*,
                    ca.id AS allocation_id,
                    ca.org_unit_id,
                    cs.id AS cycle_scholarship_id,
                    sar.student_teaching_required,
                    app.student_teaching_term_id
                 FROM recommendations r
                 JOIN cycle_allocations ca ON ca.id = r.cycle_allocation_id
                 JOIN cycle_scholarships cs ON cs.id = ca.cycle_scholarship_id
                 LEFT JOIN scholarship_award_rules sar ON sar.intent_version_id = cs.intent_version_id
                 LEFT JOIN applications app
                   ON app.cycle_id = cs.cycle_id
                  AND app.student_id = r.student_id
                 WHERE r.id = ? AND cs.cycle_id = ?"
            );
            $stmt->execute([$recommendationId, (int) $cycle['id']]);
            $recommendation = $stmt->fetch();

            if (!$recommendation) {
                throw new RuntimeException('Recommendation could not be found in the current cycle.');
            }

            $existingStmt = $this->pdo->prepare('SELECT id FROM awards WHERE recommendation_id = ? LIMIT 1');
            $existingStmt->execute([$recommendationId]);
            if ($existingStmt->fetchColumn()) {
                throw new RuntimeException('An award already exists for this recommendation.');
            }

            $awardService = new AwardService($this->pdo, new DistributionService());
            $awardId = $awardService->createFromRecommendation(
                $recommendationId,
                (int) $cycle['id'],
                (int) $recommendation['student_id'],
                (int) $recommendation['cycle_scholarship_id'],
                (int) $recommendation['allocation_id'],
                (float) $recommendation['recommended_amount'],
                (string) ($recommendation['eligibility_status_at_submission'] ?: 'insufficient_information'),
                'academic_year'
            );

            $terms = $this->cycleTerms((int) $cycle['id']);
            $studentTeaching = (bool) ($recommendation['student_teaching_required'] ?? false);
            $studentTeachingTermId = $recommendation['student_teaching_term_id']
                ? (int) $recommendation['student_teaching_term_id']
                : null;

            $distributions = (new DistributionService())->defaultDistributions(
                (float) $recommendation['recommended_amount'],
                'academic_year',
                $terms['fall'],
                $terms['spring'],
                $studentTeaching,
                $studentTeachingTermId
            );

            if ($distributions !== []) {
                $awardService->replaceDistributions($awardId, $distributions);
            }

            $this->audit(
                'award.created_from_recommendation',
                'award',
                $awardId,
                (int) $cycle['id'],
                null,
                ['recommendation_id'=>$recommendationId]
            );

            Flash::success(
                $studentTeaching && $studentTeachingTermId === null
                    ? 'Award moved to verification. Student-teaching semester still needs to be identified before notification.'
                    : 'Award moved to enrollment verification.'
            );
        } catch (\Throwable $e) {
            Flash::error($e->getMessage());
        }

        $this->redirect('/dean/recommendations');
    }

    public function verification(): string
    {
        $this->requireAdmin();
        $cycle = $this->requireCurrentCycle();
        $terms = $this->cycleTerms((int) $cycle['id']);
        $enrollment = new EnrollmentService($this->pdo);

        $stmt = $this->pdo->prepare(
            "SELECT
                a.*,
                st.display_name AS student_name,
                st.university_id,
                s.name AS scholarship_name,
                sar.student_teaching_required,
                app.student_teaching_term_id,
                COALESCE(ou.name, 'Dean''s Office') AS unit_name,
                (SELECT COUNT(*) FROM award_distributions ad WHERE ad.award_id = a.id) AS distribution_count
             FROM awards a
             JOIN students st ON st.id = a.student_id
             JOIN cycle_scholarships cs ON cs.id = a.cycle_scholarship_id
             JOIN scholarships s ON s.id = cs.scholarship_id
             LEFT JOIN scholarship_award_rules sar ON sar.intent_version_id = cs.intent_version_id
             LEFT JOIN applications app
               ON app.cycle_id = a.cycle_id
              AND app.student_id = a.student_id
             LEFT JOIN cycle_allocations ca ON ca.id = a.cycle_allocation_id
             LEFT JOIN org_units ou ON ou.id = ca.org_unit_id
             WHERE a.cycle_id = ?
               AND a.status IN ('pending_verification','approved','ready_to_notify')
             ORDER BY s.name, st.last_name, st.first_name"
        );
        $stmt->execute([(int) $cycle['id']]);

        $rows = '';
        foreach ($stmt->fetchAll() as $award) {
            $termId = $this->verificationTerm($award, $terms);
            $status = $termId
                ? $enrollment->currentStatus((int) $award['student_id'], $termId)
                : ['status'=>'warning','credit_hours'=>null,'snapshot_completed_at'=>null];

            $termName = $termId === $terms['spring'] ? 'Spring' : ($termId === $terms['fall'] ? 'Fall' : 'Needs term');
            $snapshot = $status['snapshot_completed_at']
                ? date('M j, Y', strtotime((string) $status['snapshot_completed_at']))
                : '—';
            $hours = $status['credit_hours'] !== null ? (string) $status['credit_hours'] . ' SH' : '—';

            $action = '';
            if ($award['status'] === 'ready_to_notify') {
                $action = '<a class="button secondary small" href="/notifications/ready">Ready to notify</a>';
            } elseif (
                $status['status'] === 'verified_enrolled'
                && (int) $award['distribution_count'] > 0
            ) {
                $action = '<form method="post" action="/dean/verification/' . (int) $award['id'] . '/approve">'
                    . View::csrfField()
                    . '<input type="hidden" name="term_id" value="' . (int) $termId . '">'
                    . '<button class="small" type="submit">Approve award</button></form>';
            } elseif ((bool) ($award['student_teaching_required'] ?? false) && !$award['student_teaching_term_id']) {
                $action = '<form method="post" action="/dean/verification/' . (int) $award['id'] . '/student-teaching-term">'
                    . View::csrfField()
                    . '<label class="muted" for="term_' . (int) $award['id'] . '">Student-teaching term</label>'
                    . '<select id="term_' . (int) $award['id'] . '" name="term_id" required>'
                    . '<option value="' . (int) $terms['fall'] . '">Fall</option>'
                    . '<option value="' . (int) $terms['spring'] . '">Spring</option>'
                    . '</select>'
                    . '<button class="small" type="submit" style="margin-top:.4rem">Set term</button></form>';
            } elseif ((int) $award['distribution_count'] === 0) {
                $action = '<span class="muted">Distribution term required</span>';
            } else {
                $action = '<span class="muted">Wait for enrollment</span>';
            }

            $rows .= '<tr>'
                . '<td>' . View::e($award['scholarship_name']) . '</td>'
                . '<td><strong>' . View::e($award['student_name']) . '</strong><br><span class="muted">' . View::e($award['university_id']) . '</span></td>'
                . '<td>' . View::e($award['unit_name']) . '</td>'
                . '<td class="num">' . View::money($award['total_amount']) . '</td>'
                . '<td>' . View::e($termName) . '</td>'
                . '<td>' . View::status($status['status']) . '<br><span class="muted">' . View::e($hours) . ' · ' . View::e($snapshot) . '</span></td>'
                . '<td>' . View::status($award['eligibility_status']) . '</td>'
                . '<td>' . $action . '</td>'
                . '</tr>';
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="8">No awards are currently in enrollment verification.</td></tr>';
        }

        $body = <<<HTML
<div class="page-header">
    <div>
        <h1>Enrollment verification</h1>
        <p>Enrollment is based on the newest successful complete MAUI snapshot. If a previously enrolled student disappears from a later snapshot, the current status becomes Not Enrolled.</p>
    </div>
    <div class="actions">
        <a class="button secondary" href="/admin/enrollment">Import enrollment</a>
        <a class="button secondary" href="/dean/recommendations">Recommendations</a>
    </div>
</div>

<div class="table-wrap">
<table>
<thead><tr><th>Scholarship</th><th>Student</th><th>Program</th><th class="num">Amount</th><th>Term</th><th>Enrollment</th><th>Eligibility</th><th>Action</th></tr></thead>
<tbody>{$rows}</tbody>
</table>
</div>
HTML;

        return $this->render('Enrollment verification', $body, $cycle);
    }

    public function setStudentTeachingTerm(array $params): never
    {
        $this->requireAdmin();
        $this->requirePost();
        $cycle = $this->requireCurrentCycle();
        $awardId = (int) ($params['id'] ?? 0);
        $termId = (int) ($_POST['term_id'] ?? 0);

        try {
            $stmt = $this->pdo->prepare(
                "SELECT a.*, app.id AS application_id, sar.student_teaching_required
                 FROM awards a
                 JOIN cycle_scholarships cs ON cs.id = a.cycle_scholarship_id
                 LEFT JOIN scholarship_award_rules sar ON sar.intent_version_id = cs.intent_version_id
                 LEFT JOIN applications app
                   ON app.cycle_id = a.cycle_id
                  AND app.student_id = a.student_id
                 WHERE a.id = ? AND a.cycle_id = ?"
            );
            $stmt->execute([$awardId, (int) $cycle['id']]);
            $award = $stmt->fetch();

            if (!$award || !(bool) ($award['student_teaching_required'] ?? false)) {
                throw new RuntimeException('This award is not configured as a student-teaching scholarship.');
            }

            $termStmt = $this->pdo->prepare(
                'SELECT season FROM academic_terms WHERE id = ? AND cycle_id = ?'
            );
            $termStmt->execute([$termId, (int) $cycle['id']]);
            $season = $termStmt->fetchColumn();

            if (!in_array($season, ['fall','spring'], true)) {
                throw new RuntimeException('Choose a valid Fall or Spring term.');
            }

            if ($award['application_id']) {
                $this->pdo->prepare(
                    'UPDATE applications SET student_teaching_term_id = ? WHERE id = ?'
                )->execute([$termId, $award['application_id']]);
            }

            $service = new AwardService($this->pdo, new DistributionService());
            $service->replaceDistributions($awardId, [[
                'academic_term_id' => $termId,
                'amount' => (float) $award['total_amount'],
                'source' => 'deans_office',
            ]]);

            $this->audit(
                'award.student_teaching_term_set',
                'award',
                $awardId,
                (int) $cycle['id'],
                null,
                ['term_id'=>$termId]
            );

            Flash::success('Student-teaching term saved and the award distribution updated.');
        } catch (\Throwable $e) {
            Flash::error($e->getMessage());
        }

        $this->redirect('/dean/verification');
    }

    public function approve(array $params): never
    {
        $this->requireAdmin();
        $this->requirePost();
        $cycle = $this->requireCurrentCycle();
        $awardId = (int) ($params['id'] ?? 0);
        $termId = (int) ($_POST['term_id'] ?? 0);

        try {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM awards WHERE id = ? AND cycle_id = ?'
            );
            $stmt->execute([$awardId, (int) $cycle['id']]);
            $award = $stmt->fetch();

            if (!$award) {
                throw new RuntimeException('Award not found.');
            }

            $status = (new EnrollmentService($this->pdo))->currentStatus(
                (int) $award['student_id'],
                $termId
            );

            if ($status['status'] !== 'verified_enrolled') {
                throw new RuntimeException('The student is not currently verified as enrolled for the required term.');
            }

            $this->pdo->prepare(
                "UPDATE awards
                 SET enrollment_status = 'verified_enrolled',
                     status = 'approved',
                     approved_by_user_id = ?,
                     approved_at = NOW()
                 WHERE id = ?"
            )->execute([$this->auth->userId(), $awardId]);

            (new AwardService($this->pdo, new DistributionService()))
                ->markReadyToNotify($awardId);

            $this->audit(
                'award.approved',
                'award',
                $awardId,
                (int) $cycle['id'],
                null,
                ['enrollment_import_id'=>$status['import_id'],'term_id'=>$termId]
            );

            Flash::success('Award approved and moved to Ready to Notify.');
        } catch (\Throwable $e) {
            Flash::error($e->getMessage());
        }

        $this->redirect('/dean/verification');
    }

    private function cycleTerms(int $cycleId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, season FROM academic_terms WHERE cycle_id = ?'
        );
        $stmt->execute([$cycleId]);

        $terms = [];
        foreach ($stmt->fetchAll() as $term) {
            $terms[$term['season']] = (int) $term['id'];
        }

        if (!isset($terms['fall'], $terms['spring'])) {
            throw new RuntimeException('The academic cycle must have both Fall and Spring terms.');
        }

        return $terms;
    }

    private function verificationTerm(array $award, array $terms): ?int
    {
        if ((bool) ($award['student_teaching_required'] ?? false)) {
            return $award['student_teaching_term_id']
                ? (int) $award['student_teaching_term_id']
                : null;
        }

        return match ($award['award_period']) {
            'spring' => $terms['spring'],
            'fall', 'academic_year' => $terms['fall'],
            default => $terms['fall'],
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Database;

use App\Support\Env;
use PDO;
use RuntimeException;

/**
 * Wipes scholarship *transaction* records in an explicitly named testing cycle.
 * Maintains schema, departments, programs, offerings, staff, terms, checklists,
 * reviewer assignments and reusable template configuration.
 *
 * No foreign key checks are disabled. Any unexpected reference aborts the
 * transaction instead of silently orphaning configuration or real data.
 */
final class TestDataCleaner
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function preview(string $label): array
    {
        $cycle = $this->cycle($label);
        $id = (int)$cycle['id'];
        $counts = [
            'scholarship_funds' => $this->count(
                'SELECT COUNT(*) FROM cycle_scholarships WHERE cycle_id = ?', [$id]
            ),
            'allocations' => $this->count(
                'SELECT COUNT(*) FROM cycle_allocations a JOIN cycle_scholarships s ON s.id = a.cycle_scholarship_id WHERE s.cycle_id = ?', [$id]
            ),
            'applications' => $this->count('SELECT COUNT(*) FROM applications WHERE cycle_id = ?', [$id]),
            'students' => $this->count(
                'SELECT COUNT(DISTINCT x.student_id) FROM (
                     SELECT student_id FROM applications WHERE cycle_id = ?
                     UNION SELECT student_id FROM awards WHERE cycle_id = ?
                     UNION SELECT er.student_id FROM enrollment_records er
                     JOIN enrollment_imports ei ON ei.id = er.enrollment_import_id WHERE ei.cycle_id = ?
                 ) x',
                [$id,$id,$id]
            ),
            'awards' => $this->count('SELECT COUNT(*) FROM awards WHERE cycle_id = ?', [$id]),
            'recommendations' => $this->count(
                'SELECT COUNT(*) FROM recommendations r
                 JOIN cycle_allocations a ON a.id = r.cycle_allocation_id
                 JOIN cycle_scholarships s ON s.id = a.cycle_scholarship_id
                 WHERE s.cycle_id = ?', [$id]
            ),
            'applicant_imports' => $this->count('SELECT COUNT(*) FROM application_imports WHERE cycle_id = ?', [$id]),
            'enrollment_imports' => $this->count('SELECT COUNT(*) FROM enrollment_imports WHERE cycle_id = ?', [$id]),
            'authority_imports' => $this->count('SELECT COUNT(*) FROM annual_authority_imports WHERE cycle_id = ?', [$id]),
            'historical_awards' => $this->count('SELECT COUNT(*) FROM historical_awards WHERE cycle_id = ?', [$id]),
        ];
        return ['cycle' => $cycle, 'counts' => $counts];
    }

    public function clean(string $label): array
    {
        if ((Env::get('APP_ENV', 'development') ?? 'development') !== 'testing') {
            throw new RuntimeException('Test-data cleanup is only allowed with APP_ENV=testing.');
        }
        $preview = $this->preview($label);
        $cycle = $preview['cycle'];
        $cycleId = (int)$cycle['id'];
        if (in_array($cycle['status'], ['complete','archived'], true)) {
            throw new RuntimeException('Completed or archived academic cycles cannot be reset.');
        }

        // A testing server can still contain real student records. Refuse to
        // reset the cycle unless every linked student is explicitly synthetic.
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM students st WHERE st.id IN (
                SELECT student_id FROM applications WHERE cycle_id = ?
                UNION SELECT student_id FROM awards WHERE cycle_id = ?
                UNION SELECT er.student_id FROM enrollment_records er
                    JOIN enrollment_imports ei ON ei.id = er.enrollment_import_id WHERE ei.cycle_id = ?
                UNION SELECT student_id FROM historical_awards WHERE cycle_id = ?
                UNION SELECT rec.student_id FROM recommendations rec
                  JOIN cycle_allocations ca ON ca.id = rec.cycle_allocation_id
                  JOIN cycle_scholarships cs ON cs.id = ca.cycle_scholarship_id
                  WHERE cs.cycle_id = ?
             ) AND LOWER(st.email) NOT LIKE '%@example.test'"
        );
        $stmt->execute([$cycleId,$cycleId,$cycleId,$cycleId,$cycleId]);
        if ((int)$stmt->fetchColumn() > 0) {
            throw new RuntimeException(
                'Cleanup stopped: this cycle contains students without @example.test email addresses. '
                . 'No student or scholarship records were deleted.'
            );
        }

        // Do not allow a past-cycle award to be erased if it is serving as a
        // renewal source in another cycle.
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM renewal_candidates rc
             JOIN awards a ON a.id = rc.prior_award_id
             WHERE a.cycle_id = ? AND rc.cycle_id <> ?'
        );
        $stmt->execute([$cycleId,$cycleId]);
        if ((int)$stmt->fetchColumn() > 0) {
            throw new RuntimeException('Another cycle references these awards for renewals.');
        }

        $this->pdo->beginTransaction();
        try {
            $studentsStmt = $this->pdo->prepare(
                'SELECT DISTINCT student_id FROM (
                    SELECT student_id FROM applications WHERE cycle_id = ?
                    UNION SELECT student_id FROM awards WHERE cycle_id = ?
                    UNION SELECT student_id FROM historical_awards WHERE cycle_id = ?
                    UNION SELECT er.student_id FROM enrollment_records er
                      JOIN enrollment_imports ei ON ei.id = er.enrollment_import_id WHERE ei.cycle_id = ?
                    UNION SELECT r.student_id FROM recommendations r
                      JOIN cycle_allocations ca ON ca.id = r.cycle_allocation_id
                      JOIN cycle_scholarships cs ON cs.id = ca.cycle_scholarship_id
                      WHERE cs.cycle_id = ?
                ) affected'
            );
            $studentsStmt->execute([$cycleId,$cycleId,$cycleId,$cycleId,$cycleId]);
            $candidateStudents = array_map('intval', $studentsStmt->fetchAll(PDO::FETCH_COLUMN));

            $fundIds = $this->pdo->prepare('SELECT scholarship_id FROM cycle_scholarships WHERE cycle_id = ?');
            $fundIds->execute([$cycleId]);
            $candidateFunds = array_map('intval', $fundIds->fetchAll(PDO::FETCH_COLUMN));
            $sourceFunds = $this->pdo->prepare(
                'SELECT DISTINCT sfr.scholarship_id FROM annual_fund_source_rows sfr
                 JOIN annual_authority_imports ai ON ai.id = sfr.annual_authority_import_id
                 WHERE ai.cycle_id = ? AND sfr.scholarship_id IS NOT NULL'
            );
            $sourceFunds->execute([$cycleId]);
            $candidateFunds = array_values(array_unique(array_merge(
                $candidateFunds,
                array_map('intval', $sourceFunds->fetchAll(PDO::FETCH_COLUMN))
            )));

            // Cycle-specific recipient workflow and notification references.
            $statements = [
                "DELETE d FROM thank_you_downloads d
                 JOIN thank_you_submissions t ON t.id = d.thank_you_submission_id
                 JOIN awards a ON a.id = t.award_id WHERE a.cycle_id = ?",
                "DELETE t FROM thank_you_submissions t JOIN awards a ON a.id = t.award_id WHERE a.cycle_id = ?",
                "DELETE n FROM thank_you_reminder_notifications n JOIN awards a ON a.id = n.award_id WHERE a.cycle_id = ?",
                "DELETE n FROM award_notifications n JOIN awards a ON a.id = n.award_id WHERE a.cycle_id = ?",
                "DELETE t FROM recipient_activation_tokens t JOIN awards a ON a.id = t.award_id WHERE a.cycle_id = ?",
                "DELETE d FROM distribution_requests d JOIN awards a ON a.id = d.award_id WHERE a.cycle_id = ?",
                "DELETE d FROM award_distributions d JOIN awards a ON a.id = d.award_id WHERE a.cycle_id = ?",
                'DELETE FROM awards WHERE cycle_id = ?',
                'DELETE FROM maui_export_batches WHERE cycle_id = ?',
                'DELETE FROM notification_batches WHERE cycle_id = ?',

                // Applicant/rubric/eligibility artifacts.
                "DELETE si FROM rubric_score_items si
                 JOIN rubric_scores sc ON sc.id = si.rubric_score_id
                 JOIN rubrics r ON r.id = sc.rubric_id WHERE r.cycle_id = ?",
                "DELETE sc FROM rubric_scores sc
                 JOIN rubrics r ON r.id = sc.rubric_id WHERE r.cycle_id = ?",
                "DELETE r FROM recommendations r
                 JOIN cycle_allocations a ON a.id = r.cycle_allocation_id
                 JOIN cycle_scholarships s ON s.id = a.cycle_scholarship_id
                 WHERE s.cycle_id = ?",
                'DELETE FROM renewal_candidates WHERE cycle_id = ?',
                "DELETE er FROM eligibility_rule_results er
                 JOIN eligibility_assessments ea ON ea.id = er.eligibility_assessment_id
                 JOIN cycle_scholarships s ON s.id = ea.cycle_scholarship_id WHERE s.cycle_id = ?",
                "DELETE ea FROM eligibility_assessments ea
                 JOIN cycle_scholarships s ON s.id = ea.cycle_scholarship_id WHERE s.cycle_id = ?",
                'DELETE FROM applications WHERE cycle_id = ?',
                "DELETE ir FROM application_import_rows ir
                 JOIN application_imports i ON i.id = ir.application_import_id WHERE i.cycle_id = ?",
                'DELETE FROM application_imports WHERE cycle_id = ?',
                'DELETE FROM enrollment_imports WHERE cycle_id = ?',

                // Award-authority and historical import files are retained on disk
                // for manual/retention-policy review; their cycle records vanish.
                'DELETE FROM historical_awards WHERE cycle_id = ?',
                'DELETE FROM historical_imports WHERE cycle_id = ?',
                'DELETE FROM cycle_exports WHERE cycle_id = ?',
                'DELETE FROM user_uica_access WHERE cycle_id = ?',
                "DELETE f FROM cycle_fund_financial_snapshots f
                 JOIN cycle_scholarships cs ON cs.id = f.cycle_scholarship_id WHERE cs.cycle_id = ?",
                "DELETE f FROM annual_fund_source_rows f
                 JOIN annual_authority_imports ai ON ai.id = f.annual_authority_import_id WHERE ai.cycle_id = ?",
                'DELETE FROM annual_authority_imports WHERE cycle_id = ?',
                "DELETE a FROM cycle_allocations a
                 JOIN cycle_scholarships s ON s.id = a.cycle_scholarship_id WHERE s.cycle_id = ?",
                'DELETE FROM cycle_scholarships WHERE cycle_id = ?',
            ];
            foreach ($statements as $sql) {
                $this->pdo->prepare($sql)->execute([$cycleId]);
            }

            // Catalog records imported for this test cycle can be removed if no
            // historical/other-cycle award or permanent permission uses them.
            foreach ($candidateFunds as $scholarshipId) {
                $other = $this->count(
                    'SELECT (SELECT COUNT(*) FROM cycle_scholarships WHERE scholarship_id = ?)
                         + (SELECT COUNT(*) FROM historical_awards WHERE scholarship_id = ?)
                         + (SELECT COUNT(*) FROM user_uica_access WHERE scholarship_id = ?)
                         + (SELECT COUNT(*) FROM annual_fund_source_rows WHERE scholarship_id = ?)',
                    [$scholarshipId,$scholarshipId,$scholarshipId,$scholarshipId]
                );
                if ($other > 0) {
                    continue;
                }
                $this->pdo->prepare('UPDATE scholarships SET current_intent_version_id = NULL WHERE id = ?')
                    ->execute([$scholarshipId]);
                $this->pdo->prepare(
                    'DELETE r FROM scholarship_criteria r
                     JOIN scholarship_intent_versions v ON v.id = r.intent_version_id WHERE v.scholarship_id = ?'
                )->execute([$scholarshipId]);
                $this->pdo->prepare(
                    'DELETE r FROM scholarship_award_rules r
                     JOIN scholarship_intent_versions v ON v.id = r.intent_version_id WHERE v.scholarship_id = ?'
                )->execute([$scholarshipId]);
                $this->pdo->prepare('DELETE FROM scholarship_intent_versions WHERE scholarship_id = ?')
                    ->execute([$scholarshipId]);
                $this->pdo->prepare('DELETE FROM scholarships WHERE id = ?')
                    ->execute([$scholarshipId]);
            }

            // Remove only orphan synthetic student records associated with this
            // cycle. Synthetic recipient user accounts are disabled but retained
            // for audit and account-configuration continuity.
            foreach ($candidateStudents as $studentId) {
                $emailStmt = $this->pdo->prepare('SELECT email FROM students WHERE id = ?');
                $emailStmt->execute([$studentId]);
                $email = strtolower((string)$emailStmt->fetchColumn());
                if (!str_ends_with($email, '@example.test')) {
                    continue;
                }
                $this->pdo->prepare(
                    "UPDATE users SET student_id = NULL, active = 0
                     WHERE student_id = ? AND LOWER(email) LIKE '%@example.test'"
                )->execute([$studentId]);
                $otherStudentData = $this->count(
                    'SELECT (SELECT COUNT(*) FROM applications WHERE student_id = ?)
                          + (SELECT COUNT(*) FROM awards WHERE student_id = ?)
                          + (SELECT COUNT(*) FROM recommendations WHERE student_id = ?)
                          + (SELECT COUNT(*) FROM enrollment_records WHERE student_id = ?)
                          + (SELECT COUNT(*) FROM rubric_scores WHERE student_id = ?)
                          + (SELECT COUNT(*) FROM eligibility_assessments WHERE student_id = ?)
                          + (SELECT COUNT(*) FROM renewal_candidates WHERE student_id = ?)
                          + (SELECT COUNT(*) FROM historical_awards WHERE student_id = ?)
                          + (SELECT COUNT(*) FROM application_import_rows WHERE mapped_student_id = ?)
                          + (SELECT COUNT(*) FROM users WHERE student_id = ?)',
                    array_fill(0, 10, $studentId)
                );
                if ($otherStudentData === 0) {
                    $this->pdo->prepare('DELETE FROM students WHERE id = ?')->execute([$studentId]);
                }
            }
            // Disable only the three known demo accounts. These are not
            // legitimate College reviewer assignments.
            $this->pdo->exec(
                "UPDATE users SET active = 0
                 WHERE email IN ('jordan.reviewer@example.test',
                                 'taylor.chair@example.test',
                                 'avery.johnson@example.test')"
            );

            // Preserve year, fall/spring terms, configuration and staff accounts.
            $this->pdo->prepare(
                "UPDATE academic_cycles SET status = 'setup' WHERE id = ?"
            )->execute([$cycleId]);
            $this->pdo->prepare(
                "UPDATE cycle_review_units SET status = 'not_started', submitted_at = NULL,
                 closed_at = NULL, reopened_at = NULL WHERE cycle_id = ?"
            )->execute([$cycleId]);
            $this->pdo->prepare(
                "UPDATE cycle_checklist_items SET status = 'not_started',
                 confirmed_by_user_id = NULL, confirmed_at = NULL WHERE cycle_id = ?"
            )->execute([$cycleId]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        return $preview;
    }

    private function cycle(string $label): array
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $label)) {
            throw new RuntimeException('Choose an academic-year label such as 2027-28.');
        }
        $stmt = $this->pdo->prepare('SELECT * FROM academic_cycles WHERE label = ?');
        $stmt->execute([$label]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException('The academic cycle does not exist.');
        }
        return $row;
    }

    private function count(string $sql, array $params): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }
}

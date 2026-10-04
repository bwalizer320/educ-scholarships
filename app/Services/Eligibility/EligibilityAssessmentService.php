<?php

declare(strict_types=1);

namespace App\Services\Eligibility;

use PDO;
use RuntimeException;

final class EligibilityAssessmentService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly EligibilityService $engine
    ) {
    }

    public function latestOrAssess(int $cycleScholarshipId, int $applicationId): array
    {
        [$application, $criteria, $values, $signature] = $this->context($cycleScholarshipId, $applicationId);

        $latest = $this->pdo->prepare(
            'SELECT * FROM eligibility_assessments
             WHERE cycle_scholarship_id = ?
               AND student_id = ?
               AND source_signature = ?
             ORDER BY evaluated_at DESC, id DESC
             LIMIT 1'
        );
        $latest->execute([$cycleScholarshipId, $application['student_id'], $signature]);
        $assessment = $latest->fetch();

        if ($assessment) {
            return $this->withResults($assessment);
        }

        return $this->persistAssessment(
            $cycleScholarshipId,
            $applicationId,
            (int) $application['student_id'],
            $criteria,
            $values,
            $signature
        );
    }

    private function context(int $cycleScholarshipId, int $applicationId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.*, cs.intent_version_id
             FROM applications a
             JOIN cycle_scholarships cs ON cs.cycle_id = a.cycle_id
             WHERE a.id = ? AND cs.id = ?'
        );
        $stmt->execute([$applicationId, $cycleScholarshipId]);
        $application = $stmt->fetch();

        if (!$application) {
            throw new RuntimeException('Application and scholarship are not in the same academic cycle.');
        }

        $criteriaStmt = $this->pdo->prepare(
            'SELECT * FROM scholarship_criteria
             WHERE intent_version_id = ?
             ORDER BY sort_order, id'
        );
        $criteriaStmt->execute([(int) $application['intent_version_id']]);
        $criteria = $criteriaStmt->fetchAll();

        foreach ($criteria as &$criterion) {
            $criterion['comparison_value'] = $criterion['comparison_value_json'] !== null
                ? json_decode((string) $criterion['comparison_value_json'], true)
                : null;
        }
        unset($criterion);

        $raw = $application['application_values_json']
            ? json_decode((string) $application['application_values_json'], true)
            : [];
        $raw = is_array($raw) ? $raw : [];

        $values = array_merge($raw, [
            'program_id' => $application['org_unit_id'],
            'org_unit_id' => $application['org_unit_id'],
            'program_offering_id' => $application['program_offering_id'],
            'academic_level' => $application['academic_level'],
            'degree_objective' => $application['degree_objective'],
            'classification' => $application['classification'],
            'gpa' => $application['gpa'],
            'residency_state' => $application['residency_state'],
            'residency_county' => $application['residency_county'],
            'citizenship_country' => $application['citizenship_country'],
            'first_generation' => $application['first_generation'],
            'financial_need' => $application['financial_need'],
        ]);

        $signature = hash('sha256', json_encode([
            'application' => $values,
            'criteria' => array_map(static fn(array $criterion): array => [
                'id' => $criterion['id'],
                'kind' => $criterion['criterion_kind'],
                'field' => $criterion['field_key'],
                'operator' => $criterion['operator'],
                'value' => $criterion['comparison_value'],
                'auto' => (bool) $criterion['auto_evaluable'],
            ], $criteria),
        ], JSON_THROW_ON_ERROR));

        return [$application, $criteria, $values, $signature];
    }

    private function persistAssessment(
        int $cycleScholarshipId,
        int $applicationId,
        int $studentId,
        array $criteria,
        array $values,
        string $signature
    ): array {
        $evaluated = $this->engine->evaluate($criteria, $values);

        $this->pdo->beginTransaction();

        try {
            $insert = $this->pdo->prepare(
                'INSERT INTO eligibility_assessments (
                    cycle_scholarship_id, student_id, application_id,
                    status, evaluated_at, source_signature
                 ) VALUES (?, ?, ?, ?, NOW(), ?)'
            );
            $insert->execute([
                $cycleScholarshipId,
                $studentId,
                $applicationId,
                $evaluated['status'],
                $signature,
            ]);
            $assessmentId = (int) $this->pdo->lastInsertId();

            $resultInsert = $this->pdo->prepare(
                'INSERT INTO eligibility_rule_results (
                    eligibility_assessment_id, scholarship_criterion_id, result,
                    source_name, source_value_display, source_value_json, message
                 ) VALUES (?, ?, ?, ?, ?, ?, ?)'
            );

            foreach ($evaluated['results'] as $result) {
                if (empty($result['criterion_id'])) {
                    continue;
                }

                $resultInsert->execute([
                    $assessmentId,
                    $result['criterion_id'],
                    $result['result'],
                    $result['source_name'],
                    $result['source_value_display'],
                    $result['source_value'] === null
                        ? null
                        : json_encode($result['source_value'], JSON_THROW_ON_ERROR),
                    $result['message'],
                ]);
            }

            $this->pdo->commit();

            $assessment = [
                'id' => $assessmentId,
                'cycle_scholarship_id' => $cycleScholarshipId,
                'student_id' => $studentId,
                'application_id' => $applicationId,
                'status' => $evaluated['status'],
                'source_signature' => $signature,
            ];

            return $this->withResults($assessment);
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function withResults(array $assessment): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT err.*, sc.criterion_kind, sc.display_label, sc.display_requirement
             FROM eligibility_rule_results err
             JOIN scholarship_criteria sc ON sc.id = err.scholarship_criterion_id
             WHERE err.eligibility_assessment_id = ?
             ORDER BY sc.sort_order, sc.id'
        );
        $stmt->execute([(int) $assessment['id']]);

        $assessment['results'] = $stmt->fetchAll();

        return $assessment;
    }
}

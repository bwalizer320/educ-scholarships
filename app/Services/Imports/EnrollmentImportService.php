<?php

declare(strict_types=1);

namespace App\Services\Imports;

use PDO;
use RuntimeException;

final class EnrollmentImportService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly MauiEnrollmentParser $parser,
        private readonly ProgramMappingService $mapping
    ) {
    }

    public function import(
        int $cycleId,
        int $academicTermId,
        string $path,
        string $filename,
        int $userId,
        ?int $fileId = null
    ): array {
        $importId = $this->createImport($cycleId, $academicTermId, $filename, $userId, $fileId);

        try {
            $rows = [];
            $unmapped = [];

            foreach ($this->parser->rows($path) as $line => $row) {
                $program = trim((string) ($row['PGMS_PROGRAM_DESCR'] ?? ''));
                $objective = trim((string) ($row['PGMS_OBJECTIVE_KEY'] ?? ''));
                $subprogram = trim((string) ($row['PGMS_SUB_PROGRAM_DESCR'] ?? ''));

                $map = null;

                if ($program !== '') {
                    $map = $this->mapping->mapAlias(
                        'maui',
                        $program,
                        $objective !== '' ? $objective : null,
                        $subprogram !== '' ? $subprogram : null
                    ) ?? $this->mapping->mapOfficialOffering(
                        $program,
                        $objective !== '' ? $objective : null,
                        $subprogram !== '' ? $subprogram : null
                    );
                }

                if ($program !== '' && $map === null) {
                    $key = implode(' | ', [
                        $program,
                        $objective !== '' ? $objective : '(no objective)',
                        $subprogram !== '' ? $subprogram : '(no subprogram)',
                    ]);
                    $unmapped[$key] = true;
                }

                $rows[] = [
                    'line' => $line,
                    'row' => $row,
                    'map' => $map,
                ];
            }

            if ($unmapped !== []) {
                $summary = 'Program mapping required: ' . implode('; ', array_keys($unmapped));
                $this->markNeedsMapping($importId, count($rows), $summary);

                return [
                    'import_id' => $importId,
                    'status' => 'needs_mapping',
                    'row_count' => count($rows),
                    'unmapped_programs' => array_keys($unmapped),
                ];
            }

            $this->persistRows($importId, $rows);

            return [
                'import_id' => $importId,
                'status' => 'completed',
                'row_count' => count($rows),
                'student_count' => $this->countImportStudents($importId),
                'unmapped_programs' => [],
            ];
        } catch (\Throwable $e) {
            $this->markFailed($importId, $e->getMessage());
            throw $e;
        }
    }

    private function createImport(
        int $cycleId,
        int $academicTermId,
        string $filename,
        int $userId,
        ?int $fileId
    ): int {
        $stmt = $this->pdo->prepare(
            "INSERT INTO enrollment_imports (
                cycle_id, academic_term_id, file_id, filename, status,
                is_complete_snapshot, imported_by_user_id
             ) VALUES (?, ?, ?, ?, 'processing', 1, ?)"
        );
        $stmt->execute([$cycleId, $academicTermId, $fileId, $filename, $userId]);

        return (int) $this->pdo->lastInsertId();
    }

    private function persistRows(int $importId, array $rows): void
    {
        $this->pdo->beginTransaction();

        try {
            $studentSelect = $this->pdo->prepare(
                'SELECT id FROM students WHERE university_id = ? LIMIT 1'
            );

            $studentInsert = $this->pdo->prepare(
                'INSERT INTO students (
                    public_id, university_id, first_name, last_name, display_name, email
                 ) VALUES (UUID(), ?, ?, ?, ?, ?)'
            );

            $studentUpdate = $this->pdo->prepare(
                'UPDATE students
                 SET display_name = ?, email = ?
                 WHERE id = ?'
            );

            $recordInsert = $this->pdo->prepare(
                'INSERT INTO enrollment_records (
                    enrollment_import_id, student_id, session_descr, university_id,
                    standard_full_name, email, program_college_acad_key,
                    stud_classification_descr, pgms_program_descr, pgms_objective_key,
                    is_primary, enrollment_status, citizenship_country_descr,
                    cum_ui_graded_gpa, enrolled_credit_hours, home_country, home_county,
                    home_state_descr, is_parent_higher_ed_grad, pgms_sub_program_descr,
                    pos_overall_graded_gpa, pos_ui_graded_gpa, pos_ui_graded_hours,
                    residency_county_descr, residency_state_descr, true_residency_descr,
                    veteran_status, program_offering_id, org_unit_id, raw_json
                 ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
                 )'
            );

            foreach ($rows as $entry) {
                $row = $entry['row'];
                $map = $entry['map'];
                $universityId = trim((string) ($row['UNIVERSITY_ID'] ?? ''));

                if ($universityId === '') {
                    throw new RuntimeException('MAUI row is missing University ID.');
                }

                $displayName = trim((string) ($row['STANDARD_FULL_NAME'] ?? ''));
                $email = trim((string) ($row['EMAIL'] ?? ''));
                [$firstName, $lastName] = $this->splitName($displayName);

                $studentSelect->execute([$universityId]);
                $studentId = $studentSelect->fetchColumn();

                if ($studentId === false) {
                    $studentInsert->execute([
                        $universityId,
                        $firstName,
                        $lastName,
                        $displayName !== '' ? $displayName : $firstName . ' ' . $lastName,
                        $email,
                    ]);
                    $studentId = (int) $this->pdo->lastInsertId();
                } else {
                    $studentId = (int) $studentId;
                    $studentUpdate->execute([
                        $displayName !== '' ? $displayName : $firstName . ' ' . $lastName,
                        $email,
                        $studentId,
                    ]);
                }

                $recordInsert->execute([
                    $importId,
                    $studentId,
                    $row['SESSION_DESCR'] ?? null,
                    $universityId,
                    $displayName !== '' ? $displayName : null,
                    $email !== '' ? $email : null,
                    $row['PROGRAM_COLLEGE_ACAD_KEY'] ?? null,
                    $row['STUD_CLASSIFICATION_DESCR'] ?? null,
                    $row['PGMS_PROGRAM_DESCR'] ?? null,
                    $row['PGMS_OBJECTIVE_KEY'] ?? null,
                    $this->boolToDb($this->parser->toNullableBool($row['IS_PRIMARY'] ?? null)),
                    $row['ENROLLMENT_STATUS'] ?? null,
                    $row['CITIZENSHIP_COUNTRY_DESCR'] ?? null,
                    $this->parser->toNullableFloat($row['CUM_UI_GRADED_GPA'] ?? null),
                    $this->parser->toNullableFloat($row['ENROLLED_CREDIT_HOURS'] ?? null),
                    $row['HOME_COUNTRY'] ?? null,
                    $row['HOME_COUNTY'] ?? null,
                    $row['HOME_STATE_DESCR'] ?? null,
                    $this->boolToDb($this->parser->toNullableBool($row['IS_PARENT_HIGHER_ED_GRAD'] ?? null)),
                    $row['PGMS_SUB_PROGRAM_DESCR'] ?? null,
                    $this->parser->toNullableFloat($row['POS_OVERALL_GRADED_GPA'] ?? null),
                    $this->parser->toNullableFloat($row['POS_UI_GRADED_GPA'] ?? null),
                    $this->parser->toNullableFloat($row['POS_UI_GRADED_HOURS'] ?? null),
                    $row['RESIDENCY_COUNTY_DESCR'] ?? null,
                    $row['RESIDENCY_STATE_DESCR'] ?? null,
                    $row['TRUE_RESIDENCY_DESCR'] ?? null,
                    $row['VETERAN_STATUS'] ?? null,
                    $map['program_offering_id'] ?? null,
                    $map['org_unit_id'] ?? null,
                    json_encode($row, JSON_THROW_ON_ERROR),
                ]);
            }

            $studentCount = $this->countImportStudents($importId);

            $update = $this->pdo->prepare(
                "UPDATE enrollment_imports
                 SET status = 'completed',
                     row_count = ?,
                     student_count = ?,
                     completed_at = NOW(),
                     error_summary = NULL
                 WHERE id = ?"
            );
            $update->execute([count($rows), $studentCount, $importId]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function countImportStudents(int $importId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(DISTINCT student_id) FROM enrollment_records WHERE enrollment_import_id = ?'
        );
        $stmt->execute([$importId]);

        return (int) $stmt->fetchColumn();
    }

    private function markNeedsMapping(int $importId, int $rowCount, string $summary): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE enrollment_imports
             SET status = 'needs_mapping', row_count = ?, error_summary = ?
             WHERE id = ?"
        );
        $stmt->execute([$rowCount, $summary, $importId]);
    }

    private function markFailed(int $importId, string $summary): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE enrollment_imports
             SET status = 'failed', error_summary = ?
             WHERE id = ?"
        );
        $stmt->execute([$summary, $importId]);
    }

    private function splitName(string $displayName): array
    {
        if (str_contains($displayName, ',')) {
            [$last, $first] = array_map('trim', explode(',', $displayName, 2));
            return [$first !== '' ? $first : 'Student', $last !== '' ? $last : 'Unknown'];
        }

        $parts = preg_split('/\s+/', trim($displayName)) ?: [];
        $first = array_shift($parts) ?: 'Student';
        $last = $parts === [] ? 'Unknown' : array_pop($parts);

        return [$first, $last];
    }

    private function boolToDb(?bool $value): ?int
    {
        return $value === null ? null : ($value ? 1 : 0);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Imports;

use App\Storage\LocalFileStorage;
use PDO;
use RuntimeException;

final class ApplicantImportService
{
    private const REQUIRED_MAPPING = ['university_id', 'email', 'program'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly LocalFileStorage $storage,
        private readonly TabularFileReader $reader,
        private readonly ProgramMappingService $programMapping
    ) {
    }

    public function stage(
        int $cycleId,
        int $userId,
        string $tmpPath,
        string $originalFilename
    ): array {
        $stored = $this->storage->storeUploaded($tmpPath, $originalFilename, 'applicant-imports');

        $this->pdo->beginTransaction();

        try {
            $fileStmt = $this->pdo->prepare(
                'INSERT INTO file_objects (
                    public_id, storage_driver, storage_key, original_filename,
                    mime_type, size_bytes, sha256, uploaded_by_user_id
                 ) VALUES (UUID(), ?, ?, ?, ?, ?, ?, ?)'
            );
            $fileStmt->execute([
                $stored['storage_driver'],
                $stored['storage_key'],
                $stored['original_filename'],
                $stored['mime_type'],
                $stored['size_bytes'],
                $stored['sha256'],
                $userId,
            ]);
            $fileId = (int) $this->pdo->lastInsertId();

            $headers = $this->reader->headers($stored['path'], $originalFilename);
            if ($headers === []) {
                throw new RuntimeException('No applicant import columns were found.');
            }

            $importStmt = $this->pdo->prepare(
                "INSERT INTO application_imports (
                    cycle_id, file_id, filename, mapping_profile, status, imported_by_user_id
                 ) VALUES (?, ?, ?, 'custom', 'uploaded', ?)"
            );
            $importStmt->execute([$cycleId, $fileId, $originalFilename, $userId]);
            $importId = (int) $this->pdo->lastInsertId();

            $this->pdo->commit();

            return [
                'import_id' => $importId,
                'headers' => $headers,
                'preview' => iterator_to_array($this->reader->rows($stored['path'], $originalFilename, 5)),
            ];
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function preview(int $importId): array
    {
        $import = $this->findImport($importId);
        $path = $this->storage->path($import['storage_key']);

        return [
            'import' => $import,
            'headers' => $this->reader->headers($path, $import['filename']),
            'preview' => iterator_to_array($this->reader->rows($path, $import['filename'], 5)),
        ];
    }

    public function process(int $importId, array $mapping): array
    {
        foreach (self::REQUIRED_MAPPING as $required) {
            if (empty($mapping[$required])) {
                throw new RuntimeException('Map University ID, email, and program before importing.');
            }
        }

        $import = $this->findImport($importId);
        $path = $this->storage->path($import['storage_key']);
        $cycleId = (int) $import['cycle_id'];

        $inserted = 0;
        $updated = 0;
        $errors = 0;
        $rowCount = 0;
        $needsMapping = 0;

        $this->pdo->beginTransaction();

        try {
            $clear = $this->pdo->prepare('DELETE FROM application_import_rows WHERE application_import_id = ?');
            $clear->execute([$importId]);

            foreach ($this->reader->rows($path, $import['filename']) as $rowNumber => $row) {
                $rowCount++;
                $universityId = $this->normalizeUniversityId($this->value($row, $mapping['university_id'] ?? null));
                $email = trim($this->value($row, $mapping['email'] ?? null));
                $program = trim($this->value($row, $mapping['program'] ?? null));
                $objective = trim($this->value($row, $mapping['objective'] ?? null));
                $subprogram = trim($this->value($row, $mapping['subprogram'] ?? null));
                $subprogramId = trim($this->value($row, $mapping['subprogram_id'] ?? null));

                if ($universityId === '' || $email === '' || $program === '') {
                    $errors++;
                    $this->stageRow($importId, $rowNumber + 1, $universityId ?: null, null, null, 'error', $row, [
                        'message' => 'University ID, email, or program is missing.',
                    ]);
                    continue;
                }

                $programMap = $this->programMapping->mapAlias(
                    'scholarship_application',
                    $program,
                    $objective !== '' ? $objective : null,
                    $subprogram !== '' ? $subprogram : null,
                    $subprogramId !== '' ? $subprogramId : null
                ) ?? $this->programMapping->mapOfficialOffering(
                    $program,
                    $objective !== '' ? $objective : null,
                    $subprogram !== '' ? $subprogram : null,
                    $subprogramId !== '' ? $subprogramId : null
                );

                if ($programMap === null) {
                    $needsMapping++;
                    $this->queueProgramMapping($importId, $program, $objective, $subprogram, $subprogramId);
                    $this->stageRow($importId, $rowNumber + 1, $universityId, null, null, 'needs_mapping', $row, [
                        'message' => 'Program must be mapped to the official College hierarchy.',
                    ]);
                    continue;
                }

                [$firstName, $lastName, $displayName] = $this->names($row, $mapping);
                $student = $this->upsertStudent($universityId, $firstName, $lastName, $displayName, $email);
                $applicationExists = $this->applicationExists($cycleId, $student['id']);

                $this->upsertApplication(
                    $cycleId,
                    $student['id'],
                    $importId,
                    $programMap,
                    $row,
                    $mapping
                );

                $this->stageRow(
                    $importId,
                    $rowNumber + 1,
                    $universityId,
                    $student['id'],
                    $programMap['org_unit_id'],
                    'imported',
                    $row,
                    null
                );

                $applicationExists ? $updated++ : $inserted++;
            }

            $status = $needsMapping > 0 ? 'needs_mapping' : ($errors > 0 ? 'completed' : 'completed');

            $update = $this->pdo->prepare(
                'UPDATE application_imports
                 SET column_mapping_json = ?, status = ?, row_count = ?,
                     inserted_count = ?, updated_count = ?, error_count = ?,
                     completed_at = CASE WHEN ? = "completed" THEN NOW() ELSE NULL END,
                     error_summary = ?
                 WHERE id = ?'
            );
            $update->execute([
                json_encode($mapping, JSON_THROW_ON_ERROR),
                $status,
                $rowCount,
                $inserted,
                $updated,
                $errors,
                $status,
                $needsMapping > 0 ? "{$needsMapping} row(s) need program mapping." : ($errors > 0 ? "{$errors} row(s) had import errors." : null),
                $importId,
            ]);

            $this->pdo->commit();

            return compact('status', 'rowCount', 'inserted', 'updated', 'errors', 'needsMapping');
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            $failed = $this->pdo->prepare(
                "UPDATE application_imports SET status = 'failed', error_summary = ? WHERE id = ?"
            );
            $failed->execute([$e->getMessage(), $importId]);

            throw $e;
        }
    }

    private function findImport(int $importId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ai.*, fo.storage_key
             FROM application_imports ai
             JOIN file_objects fo ON fo.id = ai.file_id
             WHERE ai.id = ?'
        );
        $stmt->execute([$importId]);
        $import = $stmt->fetch();

        if (!$import) {
            throw new RuntimeException('Applicant import not found.');
        }

        return $import;
    }

    private function upsertStudent(
        string $universityId,
        string $firstName,
        string $lastName,
        string $displayName,
        string $email
    ): array {
        $stmt = $this->pdo->prepare('SELECT id FROM students WHERE university_id = ?');
        $stmt->execute([$universityId]);
        $id = $stmt->fetchColumn();

        if ($id === false) {
            $insert = $this->pdo->prepare(
                'INSERT INTO students (
                    public_id, university_id, first_name, last_name, display_name, email
                 ) VALUES (UUID(), ?, ?, ?, ?, ?)'
            );
            $insert->execute([$universityId, $firstName, $lastName, $displayName, $email]);
            return ['id' => (int) $this->pdo->lastInsertId()];
        }

        $update = $this->pdo->prepare(
            'UPDATE students
             SET first_name = ?, last_name = ?, display_name = ?, email = ?
             WHERE id = ?'
        );
        $update->execute([$firstName, $lastName, $displayName, $email, $id]);

        return ['id' => (int) $id];
    }

    private function upsertApplication(
        int $cycleId,
        int $studentId,
        int $importId,
        array $programMap,
        array $row,
        array $mapping
    ): void {
        $values = [
            'academic_level' => $this->nullable($row, $mapping['academic_level'] ?? null),
            'degree_objective' => $this->nullable($row, $mapping['objective'] ?? null),
            'classification' => $this->nullable($row, $mapping['classification'] ?? null),
            'gpa' => $this->nullableFloat($row, $mapping['gpa'] ?? null),
            'residency_state' => $this->nullable($row, $mapping['residency_state'] ?? null),
            'residency_county' => $this->nullable($row, $mapping['residency_county'] ?? null),
            'citizenship_country' => $this->nullable($row, $mapping['citizenship_country'] ?? null),
            'first_generation' => $this->nullableBool($row, $mapping['first_generation'] ?? null),
            'financial_need' => $this->nullableBool($row, $mapping['financial_need'] ?? null),
        ];

        $stmt = $this->pdo->prepare(
            'INSERT INTO applications (
                cycle_id, student_id, latest_import_id, org_unit_id, program_offering_id,
                academic_level, degree_objective, classification, gpa, residency_state,
                residency_county, citizenship_country, first_generation, financial_need,
                application_values_json, application_responses_json, active
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE
                latest_import_id = VALUES(latest_import_id),
                org_unit_id = VALUES(org_unit_id),
                program_offering_id = VALUES(program_offering_id),
                academic_level = VALUES(academic_level),
                degree_objective = VALUES(degree_objective),
                classification = VALUES(classification),
                gpa = VALUES(gpa),
                residency_state = VALUES(residency_state),
                residency_county = VALUES(residency_county),
                citizenship_country = VALUES(citizenship_country),
                first_generation = VALUES(first_generation),
                financial_need = VALUES(financial_need),
                application_values_json = VALUES(application_values_json),
                application_responses_json = VALUES(application_responses_json),
                active = 1'
        );

        $stmt->execute([
            $cycleId,
            $studentId,
            $importId,
            $programMap['org_unit_id'],
            $programMap['program_offering_id'],
            $values['academic_level'],
            $values['degree_objective'],
            $values['classification'],
            $values['gpa'],
            $values['residency_state'],
            $values['residency_county'],
            $values['citizenship_country'],
            $values['first_generation'],
            $values['financial_need'],
            json_encode($row, JSON_THROW_ON_ERROR),
            json_encode($this->unmappedResponseFields($row, $mapping), JSON_THROW_ON_ERROR),
        ]);
    }

    private function applicationExists(int $cycleId, int $studentId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM applications WHERE cycle_id = ? AND student_id = ? LIMIT 1');
        $stmt->execute([$cycleId, $studentId]);

        return (bool) $stmt->fetchColumn();
    }

    private function stageRow(
        int $importId,
        int $rowNumber,
        ?string $universityId,
        ?int $studentId,
        ?int $orgUnitId,
        string $status,
        array $raw,
        ?array $error
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO application_import_rows (
                application_import_id, source_row_number, university_id,
                mapped_student_id, mapped_org_unit_id, status, raw_json, error_json
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $importId,
            $rowNumber,
            $universityId,
            $studentId,
            $orgUnitId,
            $status,
            json_encode($raw, JSON_THROW_ON_ERROR),
            $error ? json_encode($error, JSON_THROW_ON_ERROR) : null,
        ]);
    }

    private function queueProgramMapping(
        int $importId,
        string $program,
        string $objective,
        string $subprogram,
        string $subprogramId
    ): void {
        $stmt = $this->pdo->prepare(
            "INSERT INTO program_mapping_queue (
                source_system, source_program, source_objective, source_subprogram,
                source_subprogram_id, first_seen_import_type, first_seen_import_id
             ) VALUES ('scholarship_application', ?, ?, ?, ?, 'application', ?)
             ON DUPLICATE KEY UPDATE occurrence_count = occurrence_count + 1"
        );
        $stmt->execute([$program, $objective, $subprogram, $subprogramId, $importId]);
    }

    private function names(array $row, array $mapping): array
    {
        $display = trim($this->value($row, $mapping['display_name'] ?? null));
        $first = trim($this->value($row, $mapping['first_name'] ?? null));
        $last = trim($this->value($row, $mapping['last_name'] ?? null));

        if ($first !== '' || $last !== '') {
            $first = $first !== '' ? $first : 'Student';
            $last = $last !== '' ? $last : 'Unknown';
            return [$first, $last, $display !== '' ? $display : trim($first . ' ' . $last)];
        }

        if (str_contains($display, ',')) {
            [$last, $first] = array_map('trim', explode(',', $display, 2));
            return [$first ?: 'Student', $last ?: 'Unknown', $display];
        }

        $parts = preg_split('/\s+/', trim($display)) ?: [];
        $first = array_shift($parts) ?: 'Student';
        $last = $parts === [] ? 'Unknown' : array_pop($parts);

        return [$first, $last, $display !== '' ? $display : $first . ' ' . $last];
    }

    private function unmappedResponseFields(array $row, array $mapping): array
    {
        $used = array_filter(array_values($mapping));
        return array_diff_key($row, array_flip($used));
    }

    private function value(array $row, ?string $header): string
    {
        if ($header === null || $header === '') {
            return '';
        }

        return trim((string) ($row[$header] ?? ''));
    }

    private function nullable(array $row, ?string $header): ?string
    {
        $value = $this->value($row, $header);
        return $value === '' ? null : $value;
    }

    private function nullableFloat(array $row, ?string $header): ?float
    {
        $value = $this->value($row, $header);
        return $value === '' ? null : (float) preg_replace('/[^0-9.\-]/', '', $value);
    }

    private function nullableBool(array $row, ?string $header): ?int
    {
        $value = strtoupper($this->value($row, $header));
        if ($value === '') {
            return null;
        }

        if (in_array($value, ['YES','Y','TRUE','1'], true)) {
            return 1;
        }

        if (in_array($value, ['NO','N','FALSE','0'], true)) {
            return 0;
        }

        return null;
    }

    private function normalizeUniversityId(string $value): string
    {
        $value = trim($value);

        if (preg_match('/^="([^"]+)"$/', $value, $matches) === 1) {
            return $matches[1];
        }

        return trim($value, '"');
    }
}

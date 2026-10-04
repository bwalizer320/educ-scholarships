<?php

declare(strict_types=1);

namespace App\Services\Enrollment;

use PDO;

final class EnrollmentService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function currentStatus(int $studentId, int $academicTermId): array
    {
        $importStmt = $this->pdo->prepare(
            "SELECT id, completed_at
             FROM enrollment_imports
             WHERE academic_term_id = ?
               AND status = 'completed'
               AND is_complete_snapshot = 1
             ORDER BY completed_at DESC, id DESC
             LIMIT 1"
        );
        $importStmt->execute([$academicTermId]);
        $import = $importStmt->fetch();

        if (!$import) {
            return [
                'status' => 'no_successful_snapshot',
                'credit_hours' => null,
                'snapshot_completed_at' => null,
                'import_id' => null,
            ];
        }

        $recordStmt = $this->pdo->prepare(
            'SELECT
                MAX(COALESCE(enrolled_credit_hours, 0)) AS credit_hours,
                MAX(CASE WHEN UPPER(COALESCE(enrollment_status, '')) = 'ENROLLED' THEN 1 ELSE 0 END) AS has_enrolled_row
             FROM enrollment_records
             WHERE enrollment_import_id = ?
               AND student_id = ?'
        );
        $recordStmt->execute([(int) $import['id'], $studentId]);
        $record = $recordStmt->fetch();

        $creditHours = $record && $record['credit_hours'] !== null
            ? (float) $record['credit_hours']
            : null;

        $isEnrolled = $record
            && (int) ($record['has_enrolled_row'] ?? 0) === 1
            && $creditHours !== null
            && $creditHours >= 1.0;

        return [
            'status' => $isEnrolled ? 'verified_enrolled' : 'not_enrolled',
            'credit_hours' => $creditHours,
            'snapshot_completed_at' => $import['completed_at'],
            'import_id' => (int) $import['id'],
        ];
    }

    public function history(int $studentId, int $academicTermId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT
                ei.id AS import_id,
                ei.completed_at,
                MAX(COALESCE(er.enrolled_credit_hours, 0)) AS credit_hours,
                MAX(CASE WHEN UPPER(COALESCE(er.enrollment_status, '')) = 'ENROLLED' THEN 1 ELSE 0 END) AS has_enrolled_row
             FROM enrollment_imports ei
             LEFT JOIN enrollment_records er
               ON er.enrollment_import_id = ei.id
              AND er.student_id = ?
             WHERE ei.academic_term_id = ?
               AND ei.status = 'completed'
               AND ei.is_complete_snapshot = 1
             GROUP BY ei.id, ei.completed_at
             ORDER BY ei.completed_at DESC, ei.id DESC"
        );
        $stmt->execute([$studentId, $academicTermId]);

        return array_map(static function (array $row): array {
            $creditHours = $row['credit_hours'] === null ? null : (float) $row['credit_hours'];
            $enrolled = (int) $row['has_enrolled_row'] === 1 && $creditHours !== null && $creditHours >= 1.0;

            return [
                'import_id' => (int) $row['import_id'],
                'completed_at' => $row['completed_at'],
                'credit_hours' => $creditHours,
                'status' => $enrolled ? 'verified_enrolled' : 'not_enrolled',
            ];
        }, $stmt->fetchAll());
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Storage\LocalFileStorage;
use PDO;
use RuntimeException;

final class CycleReportService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly LocalFileStorage $storage
    ) {
    }

    public function generate(int $cycleId, int $userId, string $type): int
    {
        if (!in_array($type, ['awards','allocations','recipients','thank_yous','audit'], true)) {
            throw new RuntimeException('Unknown cycle export type.');
        }

        [$headers, $rows] = match ($type) {
            'awards' => $this->awards($cycleId),
            'allocations' => $this->allocations($cycleId),
            'recipients' => $this->recipients($cycleId),
            'thank_yous' => $this->thankYous($cycleId),
            'audit' => $this->audit($cycleId),
        };

        $csv = $this->csv($headers, $rows);
        $cycleStmt = $this->pdo->prepare('SELECT label FROM academic_cycles WHERE id = ?');
        $cycleStmt->execute([$cycleId]);
        $label = (string) $cycleStmt->fetchColumn();

        if ($label === '') {
            throw new RuntimeException('Academic cycle not found.');
        }

        $filename = preg_replace('/[^A-Za-z0-9_-]+/', '_', $label . '_' . $type) . '.csv';
        $stored = $this->storage->storeGenerated(
            $csv,
            $filename,
            'cycle-exports',
            'text/csv'
        );

        $this->pdo->beginTransaction();

        try {
            $file = $this->pdo->prepare(
                'INSERT INTO file_objects (
                    public_id, storage_driver, storage_key, original_filename,
                    mime_type, size_bytes, sha256, uploaded_by_user_id
                 ) VALUES (UUID(), ?, ?, ?, ?, ?, ?, ?)'
            );
            $file->execute([
                $stored['storage_driver'],
                $stored['storage_key'],
                $stored['original_filename'],
                $stored['mime_type'],
                $stored['size_bytes'],
                $stored['sha256'],
                $userId,
            ]);
            $fileId = (int) $this->pdo->lastInsertId();

            $export = $this->pdo->prepare(
                'INSERT INTO cycle_exports (
                    cycle_id, export_type, file_id, generated_by_user_id
                 ) VALUES (?, ?, ?, ?)'
            );
            $export->execute([$cycleId, $type, $fileId, $userId]);
            $exportId = (int) $this->pdo->lastInsertId();

            $this->pdo->commit();

            return $exportId;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function awards(int $cycleId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT s.uica_account_number, s.mfk, s.name AS scholarship,
                    st.university_id, st.display_name AS student,
                    ou.name AS program, a.award_origin, a.total_amount,
                    a.award_period, a.eligibility_status, a.enrollment_status,
                    a.status, a.approved_at, a.notified_at
             FROM awards a
             JOIN students st ON st.id = a.student_id
             JOIN cycle_scholarships cs ON cs.id = a.cycle_scholarship_id
             JOIN scholarships s ON s.id = cs.scholarship_id
             LEFT JOIN cycle_allocations ca ON ca.id = a.cycle_allocation_id
             LEFT JOIN org_units ou ON ou.id = ca.org_unit_id
             WHERE a.cycle_id = ?
             ORDER BY s.name, st.last_name, st.first_name"
        );
        $stmt->execute([$cycleId]);

        return [
            ['UICA Account','MFK','Scholarship','University ID','Student','Program','Origin','Total Amount','Award Period','Eligibility','Enrollment','Status','Approved At','Notified At'],
            $stmt->fetchAll(PDO::FETCH_NUM),
        ];
    }

    private function allocations(int $cycleId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT s.uica_account_number, s.name AS scholarship, ou.name AS program,
                    ca.authorized_new_amount, ca.planned_new_award_count,
                    ca.suggested_award_amount, ca.status
             FROM cycle_allocations ca
             JOIN cycle_scholarships cs ON cs.id = ca.cycle_scholarship_id
             JOIN scholarships s ON s.id = cs.scholarship_id
             JOIN org_units ou ON ou.id = ca.org_unit_id
             WHERE cs.cycle_id = ?
             ORDER BY s.name, ou.name"
        );
        $stmt->execute([$cycleId]);

        return [
            ['UICA Account','Scholarship','Program','Authorized New Amount','Planned New Awards','Suggested Award Amount','Status'],
            $stmt->fetchAll(PDO::FETCH_NUM),
        ];
    }

    private function recipients(int $cycleId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT DISTINCT st.university_id, st.display_name, st.email,
                    ou.name AS application_program,
                    COUNT(a.id) AS award_count,
                    SUM(CASE WHEN a.status <> 'cancelled' THEN a.total_amount ELSE 0 END) AS total_awarded
             FROM awards a
             JOIN students st ON st.id = a.student_id
             LEFT JOIN applications app ON app.cycle_id = a.cycle_id AND app.student_id = a.student_id
             LEFT JOIN org_units ou ON ou.id = app.org_unit_id
             WHERE a.cycle_id = ?
             GROUP BY st.id, st.university_id, st.display_name, st.email, ou.name
             ORDER BY st.last_name, st.first_name"
        );
        $stmt->execute([$cycleId]);

        return [
            ['University ID','Student','Email','Program','Award Count','Total Awarded'],
            $stmt->fetchAll(PDO::FETCH_NUM),
        ];
    }

    private function thankYous(int $cycleId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT s.uica_account_number, s.name AS scholarship,
                    st.university_id, st.display_name AS student,
                    ts.submission_method, ts.submitted_at, ts.deadline_at_snapshot,
                    ts.is_late
             FROM thank_you_submissions ts
             JOIN awards a ON a.id = ts.award_id
             JOIN students st ON st.id = ts.student_id
             JOIN cycle_scholarships cs ON cs.id = a.cycle_scholarship_id
             JOIN scholarships s ON s.id = cs.scholarship_id
             WHERE a.cycle_id = ?
             ORDER BY s.name, st.last_name, st.first_name"
        );
        $stmt->execute([$cycleId]);

        return [
            ['UICA Account','Scholarship','University ID','Student','Submission Method','Submitted At','Deadline','Late'],
            array_map(static function (array $row): array {
                $row[7] = (int) $row[7] === 1 ? 'Yes' : 'No';
                return $row;
            }, $stmt->fetchAll(PDO::FETCH_NUM)),
        ];
    }

    private function audit(int $cycleId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT al.created_at, u.display_name AS actor, al.event_type,
                    al.entity_type, al.entity_id, al.before_json, al.after_json
             FROM audit_log al
             LEFT JOIN users u ON u.id = al.actor_user_id
             WHERE al.cycle_id = ?
             ORDER BY al.created_at, al.id"
        );
        $stmt->execute([$cycleId]);

        return [
            ['Timestamp','Actor','Event','Entity Type','Entity ID','Before JSON','After JSON'],
            $stmt->fetchAll(PDO::FETCH_NUM),
        ];
    }

    private function csv(array $headers, array $rows): string
    {
        $handle = fopen('php://temp', 'w+');
        if ($handle === false) {
            throw new RuntimeException('Could not prepare CSV export.');
        }

        fputcsv($handle, $headers);
        foreach ($rows as $row) {
            fputcsv($handle, array_map(static function ($value) {
                if (is_bool($value)) {
                    return $value ? 'Yes' : 'No';
                }
                return $value;
            }, $row));
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        if ($csv === false) {
            throw new RuntimeException('Could not render CSV export.');
        }

        return $csv;
    }
}

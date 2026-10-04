<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Support\MergeTemplate;
use PDO;
use RuntimeException;

final class ThankYouReminderService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function queue(
        int $cycleId,
        int $userId,
        array $awardIds,
        string $subjectTemplate,
        string $htmlTemplate
    ): array {
        $awardIds = array_values(array_unique(array_filter(array_map('intval', $awardIds))));
        if ($awardIds === []) {
            throw new RuntimeException('Select at least one recipient.');
        }

        $batch = $this->pdo->prepare(
            "INSERT INTO notification_batches (
                cycle_id, notification_type, created_by_user_id, status, queued_at
             ) VALUES (?, 'thank_you_reminder', ?, 'queued', NOW())"
        );
        $batch->execute([$cycleId, $userId]);
        $batchId = (int) $this->pdo->lastInsertId();

        $queued = 0;
        $skipped = 0;

        foreach ($awardIds as $awardId) {
            $stmt = $this->pdo->prepare(
                "SELECT a.id, a.public_id, st.first_name, st.display_name, st.email,
                        s.name AS scholarship_name, ac.label AS academic_year,
                        ac.thank_you_due_at
                 FROM awards a
                 JOIN students st ON st.id = a.student_id
                 JOIN cycle_scholarships cs ON cs.id = a.cycle_scholarship_id
                 JOIN scholarships s ON s.id = cs.scholarship_id
                 JOIN academic_cycles ac ON ac.id = a.cycle_id
                 LEFT JOIN thank_you_submissions ts ON ts.award_id = a.id
                 WHERE a.id = ? AND a.cycle_id = ?
                   AND a.status IN ('notified','ready_to_notify','approved')
                   AND ts.id IS NULL"
            );
            $stmt->execute([$awardId, $cycleId]);
            $row = $stmt->fetch();

            if (!$row) {
                $skipped++;
                continue;
            }

            $merge = [
                'student_first_name' => $row['first_name'],
                'student_full_name' => $row['display_name'],
                'student_email' => $row['email'],
                'scholarship_name' => $row['scholarship_name'],
                'academic_year' => $row['academic_year'],
                'thank_you_deadline' => $row['thank_you_due_at']
                    ? date('F j, Y', strtotime((string) $row['thank_you_due_at']))
                    : '',
            ];

            $subject = MergeTemplate::render($subjectTemplate, $merge, false);
            $body = MergeTemplate::render($htmlTemplate, $merge, true);
            $key = hash('sha256', implode('|', [
                $awardId,
                date('Y-m-d'),
                $subject,
            ]));

            $existing = $this->pdo->prepare(
                'SELECT id FROM thank_you_reminder_notifications WHERE idempotency_key = ?'
            );
            $existing->execute([$key]);
            if ($existing->fetchColumn()) {
                $skipped++;
                continue;
            }

            $insert = $this->pdo->prepare(
                "INSERT INTO thank_you_reminder_notifications (
                    award_id, notification_batch_id, recipient_email,
                    subject_snapshot, html_body_snapshot, status, idempotency_key
                 ) VALUES (?, ?, ?, ?, ?, 'queued', ?)"
            );
            $insert->execute([
                $awardId, $batchId, $row['email'], $subject, $body, $key,
            ]);
            $notificationId = (int) $this->pdo->lastInsertId();

            $this->pdo->prepare(
                "INSERT INTO jobs (queue, job_type, payload_json, status, available_at)
                 VALUES ('mail', 'thank_you_reminder', ?, 'queued', NOW())"
            )->execute([
                json_encode(['notification_id'=>$notificationId], JSON_THROW_ON_ERROR),
            ]);

            $queued++;
        }

        return [
            'batch_id' => $batchId,
            'queued' => $queued,
            'skipped' => $skipped,
        ];
    }
}

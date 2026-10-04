<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Pdf\LetterRenderer;
use App\Storage\LocalFileStorage;
use App\Support\Env;
use App\Support\MergeTemplate;
use PDO;
use RuntimeException;

final class NotificationService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly LetterRenderer $letterRenderer
    ) {
    }

    public function awardMergeData(int $awardId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT
                a.id,
                a.public_id AS award_public_id,
                a.award_origin,
                a.total_amount,
                a.award_period,
                a.cycle_id,
                st.first_name,
                st.display_name AS student_full_name,
                st.email,
                s.name AS scholarship_name,
                ac.label AS academic_year,
                ac.thank_you_due_at,
                ac.distribution_change_due_at,
                ac.next_application_due_at,
                COALESCE((
                    SELECT ad.amount
                    FROM award_distributions ad
                    JOIN academic_terms at ON at.id = ad.academic_term_id
                    WHERE ad.award_id = a.id AND at.season = 'fall'
                    LIMIT 1
                ), 0) AS fall_amount,
                COALESCE((
                    SELECT ad.amount
                    FROM award_distributions ad
                    JOIN academic_terms at ON at.id = ad.academic_term_id
                    WHERE ad.award_id = a.id AND at.season = 'spring'
                    LIMIT 1
                ), 0) AS spring_amount,
                sar.student_teaching_required
             FROM awards a
             JOIN students st ON st.id = a.student_id
             JOIN cycle_scholarships cs ON cs.id = a.cycle_scholarship_id
             JOIN scholarships s ON s.id = cs.scholarship_id
             JOIN academic_cycles ac ON ac.id = a.cycle_id
             LEFT JOIN scholarship_award_rules sar ON sar.intent_version_id = cs.intent_version_id
             WHERE a.id = ?"
        );
        $stmt->execute([$awardId]);
        $row = $stmt->fetch();

        if (!$row) {
            throw new RuntimeException('Award not found.');
        }

        $baseUrl = rtrim(Env::get('APP_URL', '') ?? '', '/');
        $portalUrl = $baseUrl . '/portal/awards/' . $row['award_public_id'];

        return [
            'award_id' => (int) $row['id'],
            'student_first_name' => $row['first_name'],
            'student_full_name' => $row['student_full_name'],
            'student_email' => $row['email'],
            'scholarship_name' => $row['scholarship_name'],
            'academic_year' => $row['academic_year'],
            'award_total' => $this->money($row['total_amount']),
            'fall_amount' => $this->money($row['fall_amount']),
            'spring_amount' => $this->money($row['spring_amount']),
            'award_period' => ucwords(str_replace('_', ' ', $row['award_period'])),
            'award_type' => $row['award_origin'] === 'renewal' ? 'Renewal' : 'New Award',
            'letter_date' => date('F j, Y'),
            'thank_you_deadline' => $this->formatDate($row['thank_you_due_at']),
            'distribution_change_deadline' => $this->formatDate($row['distribution_change_due_at']),
            'next_application_deadline' => $this->formatDate($row['next_application_due_at']),
            'student_teaching_language' => (bool) $row['student_teaching_required']
                ? 'This scholarship is intended to be distributed during your student-teaching semester.'
                : '',
            'student_portal_url' => $portalUrl,
            'signature_name' => 'Dean’s Office',
            'signature_title' => 'University of Iowa College of Education',
        ];
    }

    public function queueAward(
        int $awardId,
        int $userId,
        ?int $batchId,
        string $subjectTemplate,
        string $emailHtmlTemplate
    ): int {
        $awardStmt = $this->pdo->prepare(
            "SELECT a.*, s.name AS scholarship_name
             FROM awards a
             JOIN cycle_scholarships cs ON cs.id = a.cycle_scholarship_id
             JOIN scholarships s ON s.id = cs.scholarship_id
             WHERE a.id = ?"
        );
        $awardStmt->execute([$awardId]);
        $award = $awardStmt->fetch();

        if (!$award || $award['status'] !== 'ready_to_notify') {
            throw new RuntimeException('Award is not Ready to Notify.');
        }

        $merge = $this->awardMergeData($awardId);
        $letterType = $award['award_origin'] === 'renewal' ? 'renewal' : 'new_award';

        $letterStmt = $this->pdo->prepare(
            'SELECT * FROM letter_templates
             WHERE template_type = ? AND active = 1
             ORDER BY version_number DESC LIMIT 1'
        );
        $letterStmt->execute([$letterType]);
        $letter = $letterStmt->fetch();

        if (!$letter) {
            throw new RuntimeException(
                $letterType === 'renewal'
                    ? 'An active renewable award letter template is required.'
                    : 'An active new award letter template is required.'
            );
        }

        $safeBase = preg_replace('/[^A-Za-z0-9]+/', '_', (string) $award['scholarship_name']) ?: 'Scholarship';
        $safeStudent = preg_replace('/[^A-Za-z0-9]+/', '_', (string) $merge['student_full_name']) ?: 'Student';
        $filename = trim($safeBase, '_') . '_' . trim($safeStudent, '_') . '.pdf';

        $letterFileId = $this->letterRenderer->renderAndStore(
            $userId,
            (string) $letter['html_template'],
            $merge,
            $filename
        );

        $subject = MergeTemplate::render($subjectTemplate, $merge, false);
        $body = MergeTemplate::render($emailHtmlTemplate, $merge, true);
        $idempotencyKey = hash('sha256', implode('|', [
            $awardId,
            $award['updated_at'],
            $merge['student_email'],
            $subject,
        ]));

        $existing = $this->pdo->prepare(
            'SELECT id FROM award_notifications WHERE idempotency_key = ? LIMIT 1'
        );
        $existing->execute([$idempotencyKey]);
        $existingId = $existing->fetchColumn();

        if ($existingId !== false) {
            return (int) $existingId;
        }

        $this->pdo->beginTransaction();

        try {
            $insert = $this->pdo->prepare(
                "INSERT INTO award_notifications (
                    award_id, notification_batch_id, recipient_email,
                    subject_snapshot, html_body_snapshot, letter_file_id,
                    portal_url_snapshot, status, idempotency_key
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, 'queued', ?)"
            );
            $insert->execute([
                $awardId,
                $batchId,
                $merge['student_email'],
                $subject,
                $body,
                $letterFileId,
                $merge['student_portal_url'],
                $idempotencyKey,
            ]);
            $notificationId = (int) $this->pdo->lastInsertId();

            $job = $this->pdo->prepare(
                "INSERT INTO jobs (queue, job_type, payload_json, status, available_at)
                 VALUES ('mail', 'award_notification', ?, 'queued', NOW())"
            );
            $job->execute([
                json_encode(['notification_id'=>$notificationId], JSON_THROW_ON_ERROR),
            ]);

            $this->pdo->commit();

            return $notificationId;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function createBatch(int $cycleId, int $userId, int $count): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO notification_batches (
                cycle_id, notification_type, created_by_user_id, status, queued_at
             ) VALUES (?, 'award', ?, 'queued', NOW())"
        );
        $stmt->execute([$cycleId, $userId]);

        return (int) $this->pdo->lastInsertId();
    }

    private function money(mixed $value): string
    {
        return '$' . number_format((float) $value, 2);
    }

    private function formatDate(mixed $value): string
    {
        if (!$value) {
            return '';
        }

        $timestamp = strtotime((string) $value);

        return $timestamp === false ? '' : date('F j, Y', $timestamp);
    }
}

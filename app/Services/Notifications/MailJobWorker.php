<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Mail\MailMessage;
use App\Mail\MailProvider;
use App\Storage\LocalFileStorage;
use PDO;
use RuntimeException;

final class MailJobWorker
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly MailProvider $mailProvider,
        private readonly LocalFileStorage $storage
    ) {
    }

    public function workOne(): bool
    {
        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->query(
                "SELECT * FROM jobs
                 WHERE queue = 'mail'
                   AND status = 'queued'
                   AND available_at <= NOW()
                 ORDER BY id
                 LIMIT 1
                 FOR UPDATE"
            );
            $job = $stmt->fetch();

            if (!$job) {
                $this->pdo->commit();
                return false;
            }

            $this->pdo->prepare(
                "UPDATE jobs
                 SET status = 'processing', reserved_at = NOW(), attempts = attempts + 1
                 WHERE id = ?"
            )->execute([(int) $job['id']]);

            $this->pdo->commit();

            try {
                $this->process($job);
                $this->pdo->prepare(
                    "UPDATE jobs SET status = 'completed', completed_at = NOW(), last_error = NULL WHERE id = ?"
                )->execute([(int) $job['id']]);

                return true;
            } catch (\Throwable $e) {
                $attempts = (int) $job['attempts'] + 1;
                $status = $attempts >= 3 ? 'failed' : 'queued';
                $available = $attempts >= 3 ? 'NOW()' : 'DATE_ADD(NOW(), INTERVAL 5 MINUTE)';

                $sql = "UPDATE jobs
                        SET status = ?, last_error = ?, available_at = {$available}
                        WHERE id = ?";
                $this->pdo->prepare($sql)->execute([$status, $e->getMessage(), (int) $job['id']]);

                return true;
            }
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    private function process(array $job): void
    {
        $payload = json_decode((string) $job['payload_json'], true, 512, JSON_THROW_ON_ERROR);

        if (empty($payload['notification_id'])) {
            throw new RuntimeException('Mail job payload is missing notification_id.');
        }

        if (($job['job_type'] ?? '') === 'award_notification') {
            $this->processAwardNotification((int) $payload['notification_id']);
            return;
        }

        if (($job['job_type'] ?? '') === 'thank_you_reminder') {
            $this->processThankYouReminder((int) $payload['notification_id']);
            return;
        }

        throw new RuntimeException('Unsupported mail job.');
    }

    private function processAwardNotification(int $notificationId): void
    {
        $stmt = $this->pdo->prepare(
            "SELECT an.*, fo.storage_key, fo.original_filename
             FROM award_notifications an
             JOIN file_objects fo ON fo.id = an.letter_file_id
             WHERE an.id = ?"
        );
        $stmt->execute([$notificationId]);
        $notification = $stmt->fetch();

        if (!$notification) {
            throw new RuntimeException('Award notification record could not be found.');
        }

        if ($notification['status'] === 'sent') {
            return;
        }

        $this->pdo->prepare(
            "UPDATE award_notifications SET status = 'sending' WHERE id = ?"
        )->execute([$notificationId]);

        $filePath = $this->storage->path((string) $notification['storage_key']);

        $result = $this->mailProvider->send(new MailMessage(
            (string) $notification['recipient_email'],
            (string) $notification['subject_snapshot'],
            (string) $notification['html_body_snapshot'],
            [[
                'path' => $filePath,
                'name' => (string) $notification['original_filename'],
            ]]
        ));

        if (!$result->success) {
            $this->pdo->prepare(
                "UPDATE award_notifications
                 SET status = 'failed', failed_at = NOW(), error_message = ?
                 WHERE id = ?"
            )->execute([$result->error, $notificationId]);

            throw new RuntimeException($result->error ?: 'Email provider reported a failure.');
        }

        $this->pdo->beginTransaction();

        try {
            $this->pdo->prepare(
                "UPDATE award_notifications
                 SET status = 'sent', provider = 'smtp', provider_message_id = ?,
                     sent_at = NOW(), failed_at = NULL, error_message = NULL
                 WHERE id = ?"
            )->execute([$result->providerMessageId, $notificationId]);

            $this->pdo->prepare(
                "UPDATE awards
                 SET status = 'notified', notified_at = NOW()
                 WHERE id = ? AND status = 'ready_to_notify'"
            )->execute([(int) $notification['award_id']]);

            if ($notification['notification_batch_id']) {
                $this->refreshBatch((int) $notification['notification_batch_id']);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function processThankYouReminder(int $notificationId): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM thank_you_reminder_notifications WHERE id = ?'
        );
        $stmt->execute([$notificationId]);
        $notification = $stmt->fetch();

        if (!$notification) {
            throw new RuntimeException('Thank-you reminder record could not be found.');
        }

        if ($notification['status'] === 'sent') {
            return;
        }

        $stillOutstanding = $this->pdo->prepare(
            'SELECT 1
             FROM awards a
             LEFT JOIN thank_you_submissions ts ON ts.award_id = a.id
             WHERE a.id = ? AND ts.id IS NULL'
        );
        $stillOutstanding->execute([(int) $notification['award_id']]);

        if (!$stillOutstanding->fetchColumn()) {
            $this->pdo->prepare(
                "UPDATE thank_you_reminder_notifications
                 SET status = 'failed', failed_at = NOW(),
                     error_message = 'Skipped because thank-you letter was already submitted.'
                 WHERE id = ?"
            )->execute([$notificationId]);
            return;
        }

        $this->pdo->prepare(
            "UPDATE thank_you_reminder_notifications SET status = 'sending' WHERE id = ?"
        )->execute([$notificationId]);

        $result = $this->mailProvider->send(new MailMessage(
            (string) $notification['recipient_email'],
            (string) $notification['subject_snapshot'],
            (string) $notification['html_body_snapshot']
        ));

        if (!$result->success) {
            $this->pdo->prepare(
                "UPDATE thank_you_reminder_notifications
                 SET status = 'failed', failed_at = NOW(), error_message = ?
                 WHERE id = ?"
            )->execute([$result->error, $notificationId]);
            throw new RuntimeException($result->error ?: 'Email provider reported a failure.');
        }

        $this->pdo->prepare(
            "UPDATE thank_you_reminder_notifications
             SET status = 'sent', provider = 'smtp', provider_message_id = ?,
                 sent_at = NOW(), failed_at = NULL, error_message = NULL
             WHERE id = ?"
        )->execute([$result->providerMessageId, $notificationId]);

        if ($notification['notification_batch_id']) {
            $this->refreshReminderBatch((int) $notification['notification_batch_id']);
        }
    }

    private function refreshReminderBatch(int $batchId): void
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) AS total,
                    SUM(status = 'sent') AS sent_count,
                    SUM(status = 'failed') AS failed_count
             FROM thank_you_reminder_notifications
             WHERE notification_batch_id = ?"
        );
        $stmt->execute([$batchId]);
        $counts = $stmt->fetch();

        $total = (int) ($counts['total'] ?? 0);
        $sent = (int) ($counts['sent_count'] ?? 0);
        $failed = (int) ($counts['failed_count'] ?? 0);

        $status = $total > 0 && $sent === $total
            ? 'completed'
            : ($failed > 0 && $sent > 0
                ? 'partial_failure'
                : ($failed === $total && $total > 0 ? 'failed' : 'processing'));

        $this->pdo->prepare(
            'UPDATE notification_batches
             SET status = ?, completed_at = CASE WHEN ? IN ("completed","partial_failure","failed") THEN NOW() ELSE NULL END
             WHERE id = ?'
        )->execute([$status, $status, $batchId]);
    }

    private function refreshBatch(int $batchId): void
    {
        $stmt = $this->pdo->prepare(
            "SELECT
                COUNT(*) AS total,
                SUM(status = 'sent') AS sent_count,
                SUM(status = 'failed') AS failed_count
             FROM award_notifications
             WHERE notification_batch_id = ?"
        );
        $stmt->execute([$batchId]);
        $counts = $stmt->fetch();

        $total = (int) ($counts['total'] ?? 0);
        $sent = (int) ($counts['sent_count'] ?? 0);
        $failed = (int) ($counts['failed_count'] ?? 0);

        if ($total > 0 && $sent === $total) {
            $status = 'completed';
        } elseif ($failed > 0 && $sent > 0) {
            $status = 'partial_failure';
        } elseif ($failed === $total && $total > 0) {
            $status = 'failed';
        } else {
            $status = 'processing';
        }

        $this->pdo->prepare(
            'UPDATE notification_batches
             SET status = ?, completed_at = CASE WHEN ? IN ("completed","partial_failure","failed") THEN NOW() ELSE NULL END
             WHERE id = ?'
        )->execute([$status, $status, $batchId]);
    }
}

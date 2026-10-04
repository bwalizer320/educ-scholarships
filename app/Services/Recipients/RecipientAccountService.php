<?php

declare(strict_types=1);

namespace App\Services\Recipients;

use App\Support\Env;
use PDO;
use RuntimeException;

final class RecipientAccountService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function ensureForAward(int $awardId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT a.id AS award_id, a.public_id AS award_public_id, a.student_id,
                    st.first_name, st.last_name, st.display_name, st.email
             FROM awards a
             JOIN students st ON st.id = a.student_id
             WHERE a.id = ?"
        );
        $stmt->execute([$awardId]);
        $row = $stmt->fetch();

        if (!$row) {
            throw new RuntimeException('Award recipient could not be found.');
        }

        $userStmt = $this->pdo->prepare(
            "SELECT u.id,
                    EXISTS(
                        SELECT 1 FROM auth_identities ai
                        WHERE ai.user_id = u.id
                          AND ai.provider = 'local'
                          AND ai.password_hash IS NOT NULL
                    ) AS has_local_password
             FROM users u
             WHERE u.student_id = ?
                OR (u.person_type = 'student' AND LOWER(u.email) = LOWER(?))
             ORDER BY u.student_id IS NOT NULL DESC
             LIMIT 1"
        );
        $userStmt->execute([(int) $row['student_id'], $row['email']]);
        $user = $userStmt->fetch();

        if ($user) {
            $userId = (int) $user['id'];
            $this->pdo->prepare(
                "UPDATE users
                 SET person_type = 'student', staff_role = NULL, student_id = ?,
                     first_name = ?, last_name = ?, display_name = ?, email = ?, active = 1
                 WHERE id = ?"
            )->execute([
                $row['student_id'],
                $row['first_name'],
                $row['last_name'],
                $row['display_name'],
                $row['email'],
                $userId,
            ]);
            $hasPassword = (bool) $user['has_local_password'];
        } else {
            $insert = $this->pdo->prepare(
                "INSERT INTO users (
                    public_id, person_type, staff_role, student_id, first_name, last_name,
                    display_name, email, active
                 ) VALUES (UUID(), 'student', NULL, ?, ?, ?, ?, ?, 1)"
            );
            $insert->execute([
                $row['student_id'],
                $row['first_name'],
                $row['last_name'],
                $row['display_name'],
                $row['email'],
            ]);
            $userId = (int) $this->pdo->lastInsertId();
            $hasPassword = false;
        }

        $baseUrl = rtrim(Env::get('APP_URL', '') ?? '', '/');
        $portalUrl = $baseUrl . '/portal/awards/' . $row['award_public_id'];
        $activationUrl = null;

        if (!$hasPassword) {
            $plainToken = bin2hex(random_bytes(24));
            $hash = hash('sha256', $plainToken);

            $this->pdo->prepare(
                "UPDATE recipient_activation_tokens
                 SET used_at = COALESCE(used_at, NOW())
                 WHERE user_id = ? AND used_at IS NULL"
            )->execute([$userId]);

            $tokenStmt = $this->pdo->prepare(
                "INSERT INTO recipient_activation_tokens (
                    user_id, award_id, token_hash, expires_at
                 ) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 14 DAY))"
            );
            $tokenStmt->execute([$userId, $awardId, $hash]);
            $activationUrl = $baseUrl . '/activate/' . $plainToken;
        }

        return [
            'user_id' => $userId,
            'portal_url' => $portalUrl,
            'activation_url' => $activationUrl,
            'has_local_password' => $hasPassword,
        ];
    }

    public function token(string $plainToken): ?array
    {
        $hash = hash('sha256', $plainToken);

        $stmt = $this->pdo->prepare(
            "SELECT rat.*, u.email, u.display_name
             FROM recipient_activation_tokens rat
             JOIN users u ON u.id = rat.user_id
             WHERE rat.token_hash = ?
               AND rat.used_at IS NULL
               AND rat.expires_at > NOW()
             LIMIT 1"
        );
        $stmt->execute([$hash]);

        return $stmt->fetch() ?: null;
    }

    public function activate(string $plainToken, string $password): int
    {
        if (strlen($password) < 12) {
            throw new RuntimeException('Password must be at least 12 characters.');
        }

        $token = $this->token($plainToken);
        if (!$token) {
            throw new RuntimeException('This activation link is invalid or has expired.');
        }

        $this->pdo->beginTransaction();

        try {
            $identity = $this->pdo->prepare(
                "INSERT INTO auth_identities (
                    user_id, provider, provider_subject, password_hash, activated_at
                 ) VALUES (?, 'local', ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE
                    password_hash = VALUES(password_hash),
                    activated_at = NOW()"
            );
            $identity->execute([
                $token['user_id'],
                strtolower((string) $token['email']),
                password_hash($password, PASSWORD_ARGON2ID),
            ]);

            $this->pdo->prepare(
                'UPDATE recipient_activation_tokens SET used_at = NOW() WHERE id = ?'
            )->execute([(int) $token['id']]);

            $this->pdo->commit();

            return (int) $token['user_id'];
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}

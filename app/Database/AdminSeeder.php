<?php

declare(strict_types=1);

namespace App\Database;

use PDO;
use RuntimeException;

final class AdminSeeder
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function createOrUpdate(
        string $name,
        string $email,
        string $password,
        ?string $hawkid = null
    ): int {
        if (trim($name) === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('A valid admin name and email are required.');
        }

        if (strlen($password) < 12) {
            throw new RuntimeException('Bootstrap admin password must be at least 12 characters.');
        }

        [$firstName, $lastName] = $this->splitName($name);

        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare('SELECT id FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1');
            $stmt->execute([$email]);
            $userId = $stmt->fetchColumn();

            if ($userId === false) {
                $insert = $this->pdo->prepare(
                    "INSERT INTO users (
                        public_id, person_type, staff_role, first_name, last_name,
                        display_name, email, hawkid, active
                     ) VALUES (UUID(), 'staff', 'system_admin', ?, ?, ?, ?, ?, 1)"
                );
                $insert->execute([$firstName, $lastName, $name, $email, $hawkid]);
                $userId = (int) $this->pdo->lastInsertId();
            } else {
                $userId = (int) $userId;
                $update = $this->pdo->prepare(
                    "UPDATE users
                     SET person_type = 'staff', staff_role = 'system_admin',
                         first_name = ?, last_name = ?, display_name = ?,
                         hawkid = ?, active = 1
                     WHERE id = ?"
                );
                $update->execute([$firstName, $lastName, $name, $hawkid, $userId]);
            }

            $identity = $this->pdo->prepare(
                "SELECT id FROM auth_identities WHERE user_id = ? AND provider = 'local' LIMIT 1"
            );
            $identity->execute([$userId]);
            $identityId = $identity->fetchColumn();

            $hash = password_hash($password, PASSWORD_ARGON2ID);

            if ($identityId === false) {
                $insertIdentity = $this->pdo->prepare(
                    "INSERT INTO auth_identities
                        (user_id, provider, provider_subject, password_hash, activated_at)
                     VALUES (?, 'local', ?, ?, NOW())"
                );
                $insertIdentity->execute([$userId, strtolower($email), $hash]);
            } else {
                $updateIdentity = $this->pdo->prepare(
                    'UPDATE auth_identities
                     SET provider_subject = ?, password_hash = ?, activated_at = NOW()
                     WHERE id = ?'
                );
                $updateIdentity->execute([strtolower($email), $hash, $identityId]);
            }

            $this->pdo->commit();
            return $userId;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $first = array_shift($parts) ?: 'Admin';
        $last = $parts === [] ? 'User' : implode(' ', $parts);

        return [$first, $last];
    }
}

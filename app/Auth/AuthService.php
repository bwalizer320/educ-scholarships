<?php

declare(strict_types=1);

namespace App\Auth;

use PDO;

final class AuthService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function attempt(string $email, string $password): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT u.*, ai.password_hash
             FROM users u
             JOIN auth_identities ai ON ai.user_id = u.id
             WHERE LOWER(u.email) = LOWER(?)
               AND u.active = 1
               AND ai.provider = 'local'
             LIMIT 1"
        );
        $stmt->execute([trim($email)]);
        $user = $stmt->fetch();

        if (!$user || empty($user['password_hash']) || !password_verify($password, $user['password_hash'])) {
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['auth_user_id'] = (int) $user['id'];
        $_SESSION['auth_person_type'] = $user['person_type'];
        $_SESSION['auth_staff_role'] = $user['staff_role'];
        $_SESSION['auth_display_name'] = $user['display_name'];

        $update = $this->pdo->prepare(
            "UPDATE auth_identities SET last_authenticated_at = NOW() WHERE user_id = ? AND provider = 'local'"
        );
        $update->execute([(int) $user['id']]);

        $this->pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')
            ->execute([(int) $user['id']]);

        return true;
    }

    public function logout(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'] ?? '',
                (bool) $params['secure'],
                (bool) $params['httponly']
            );
        }

        session_destroy();
    }

    public function check(): bool
    {
        return isset($_SESSION['auth_user_id']);
    }

    public function userId(): ?int
    {
        return isset($_SESSION['auth_user_id']) ? (int) $_SESSION['auth_user_id'] : null;
    }

    public function staffRole(): ?string
    {
        return $_SESSION['auth_staff_role'] ?? null;
    }

    public function personType(): ?string
    {
        return $_SESSION['auth_person_type'] ?? null;
    }

    public function displayName(): ?string
    {
        return $_SESSION['auth_display_name'] ?? null;
    }
}

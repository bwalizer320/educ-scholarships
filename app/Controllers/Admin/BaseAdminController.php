<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Auth\AuthService;
use App\Auth\Gate;
use App\Http\Csrf;
use App\Support\Flash;
use App\Support\View;
use PDO;
use RuntimeException;

abstract class BaseAdminController
{
    public function __construct(
        protected readonly PDO $pdo,
        protected readonly AuthService $auth
    ) {
    }

    protected function requireAdmin(): void
    {
        if (!$this->auth->check()) {
            $this->redirect('/login');
        }

        if (!Gate::isAdmin($this->auth->staffRole())) {
            http_response_code(403);
            throw new RuntimeException('You do not have permission to manage scholarship administration.');
        }
    }

    protected function requirePost(): void
    {
        Csrf::assertValid($_POST['_csrf'] ?? null);
    }

    protected function currentCycle(): ?array
    {
        $stmt = $this->pdo->query(
            'SELECT * FROM academic_cycles WHERE is_current = 1 LIMIT 1'
        );

        $cycle = $stmt->fetch();

        return $cycle ?: null;
    }

    protected function requireCurrentCycle(): array
    {
        $cycle = $this->currentCycle();

        if (!$cycle) {
            Flash::error('Choose a current academic cycle first.');
            $this->redirect('/admin/cycles');
        }

        return $cycle;
    }

    protected function render(string $title, string $body, ?array $cycle = null): string
    {
        return View::layout(
            $title,
            Flash::render() . $body,
            $this->auth->displayName(),
            $this->auth->staffRole(),
            $cycle['label'] ?? $this->currentCycle()['label'] ?? null
        );
    }

    protected function redirect(string $location): never
    {
        header('Location: ' . $location);
        exit;
    }

    protected function audit(
        string $eventType,
        string $entityType,
        int $entityId,
        ?int $cycleId = null,
        ?array $before = null,
        ?array $after = null
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO audit_log (
                actor_user_id, event_type, entity_type, entity_id, cycle_id,
                before_json, after_json, ip_address, user_agent
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $stmt->execute([
            $this->auth->userId(),
            $eventType,
            $entityType,
            $entityId,
            $cycleId,
            $before ? json_encode($before, JSON_THROW_ON_ERROR) : null,
            $after ? json_encode($after, JSON_THROW_ON_ERROR) : null,
            $_SERVER['REMOTE_ADDR'] ?? null,
            substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500) ?: null,
        ]);
    }
}

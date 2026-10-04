<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthService;
use App\Http\Csrf;
use App\Services\Recipients\RecipientAccountService;
use App\Support\Flash;
use App\Support\View;
use PDO;

final class ActivationController
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AuthService $auth
    ) {
    }

    public function form(array $params): string
    {
        $token = (string) ($params['token'] ?? '');
        $record = (new RecipientAccountService($this->pdo))->token($token);

        if (!$record) {
            http_response_code(404);
            return View::layout(
                'Activation link unavailable',
                '<div class="error"><h1>Activation link unavailable</h1><p>This activation link is invalid, expired, or has already been used.</p></div>'
            );
        }

        $body = Flash::render()
            . '<section class="card"><h1>Activate your scholarship recipient account</h1>'
            . '<p>Account: <strong>' . View::e($record['display_name']) . '</strong><br>'
            . View::e($record['email']) . '</p>'
            . '<form method="post" action="/activate/' . View::e($token) . '">'
            . View::csrfField()
            . '<label for="password">Create password</label>'
            . '<input id="password" name="password" type="password" minlength="12" autocomplete="new-password" required>'
            . '<label for="password_confirmation">Confirm password</label>'
            . '<input id="password_confirmation" name="password_confirmation" type="password" minlength="12" autocomplete="new-password" required>'
            . '<p class="muted">Use at least 12 characters. This local password is for testing; production access can use HawkID/SSO.</p>'
            . '<div class="form-actions"><button type="submit">Activate account</button></div></form></section>';

        return View::layout('Activate recipient account', $body);
    }

    public function activate(array $params): never
    {
        try {
            Csrf::assertValid($_POST['_csrf'] ?? null);

            $token = (string) ($params['token'] ?? '');
            $password = (string) ($_POST['password'] ?? '');
            $confirmation = (string) ($_POST['password_confirmation'] ?? '');

            if ($password !== $confirmation) {
                throw new \RuntimeException('Passwords do not match.');
            }

            $userId = (new RecipientAccountService($this->pdo))->activate($token, $password);
            $this->auth->loginUser($userId);
            Flash::success('Your recipient account is active.');
            header('Location: /portal');
            exit;
        } catch (\Throwable $e) {
            Flash::error($e->getMessage());
            header('Location: /activate/' . rawurlencode((string) ($params['token'] ?? '')));
            exit;
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Support\Flash;
use App\Support\View;
use RuntimeException;

final class UserController extends BaseAdminController
{
    public function index(): string
    {
        $this->requireAdmin();
        $cycle = $this->currentCycle();

        $users = $this->pdo->query(
            "SELECT u.*,
                    (SELECT GROUP_CONCAT(ou.name ORDER BY ou.name SEPARATOR ', ')
                     FROM user_unit_assignments ua
                     JOIN org_units ou ON ou.id = ua.org_unit_id
                     WHERE ua.user_id = u.id AND ua.active = 1) AS assignments
             FROM users u
             WHERE u.person_type = 'staff'
             ORDER BY u.active DESC, u.last_name, u.first_name"
        )->fetchAll();

        $rows = '';
        foreach ($users as $user) {
            $toggleLabel = (int) $user['active'] === 1 ? 'Deactivate' : 'Activate';
            $rows .= '<tr>'
                . '<td><strong>' . View::e($user['display_name']) . '</strong><br><span class="muted">' . View::e($user['email']) . '</span></td>'
                . '<td>' . View::e($user['hawkid'] ?? '—') . '</td>'
                . '<td>' . View::e(ucwords(str_replace('_', ' ', $user['staff_role'] ?? ''))) . '</td>'
                . '<td>' . View::e($user['assignments'] ?? '—') . '</td>'
                . '<td>' . ((int) $user['active'] === 1 ? View::status('confirmed') : View::status('closed')) . '</td>'
                . '<td><form method="post" action="/admin/users/' . (int) $user['id'] . '/toggle">'
                . View::csrfField()
                . '<button class="secondary small" type="submit">' . View::e($toggleLabel) . '</button></form></td>'
                . '</tr>';
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="6">No staff users have been created.</td></tr>';
        }

        $body = <<<HTML
<div class="page-header">
<div><h1>Users</h1><p>Create staff accounts for local testing and manage staff roles. Program assignments are managed on the Reviewers screen.</p></div>
</div>
<section class="card">
<h2>Add staff user</h2>
<form method="post" action="/admin/users">
{$this->csrf()}
<div class="form-grid">
<div><label for="display_name">Name</label><input id="display_name" name="display_name" required></div>
<div><label for="email">Email</label><input id="email" name="email" type="email" required></div>
<div><label for="hawkid">HawkID</label><input id="hawkid" name="hawkid"></div>
<div><label for="staff_role">Role</label>
<select id="staff_role" name="staff_role" required>
<option value="system_admin">System Administrator</option>
<option value="deans_office_admin">Dean's Office Scholarship Administrator</option>
<option value="department_chair">Department Chair</option>
<option value="program_coordinator">Program Coordinator</option>
</select></div>
<div><label for="password">Local test password</label><input id="password" name="password" type="password" autocomplete="new-password" minlength="12"><p class="muted">Optional. HawkID/SSO users will not need a local password in production.</p></div>
</div>
<div class="form-actions"><button type="submit">Create staff user</button></div>
</form>
</section>
<section class="card" style="margin-top:1rem">
<h2>Staff accounts</h2>
<div class="table-wrap"><table>
<thead><tr><th>User</th><th>HawkID</th><th>Role</th><th>Assignments</th><th>Status</th><th>Action</th></tr></thead>
<tbody>{$rows}</tbody>
</table></div>
</section>
HTML;

        return $this->render('Users', $body, $cycle);
    }

    public function create(): never
    {
        $this->requireAdmin();
        $this->requirePost();
        $cycle = $this->currentCycle();

        try {
            $name = trim((string) ($_POST['display_name'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            $hawkid = trim((string) ($_POST['hawkid'] ?? ''));
            $role = (string) ($_POST['staff_role'] ?? '');
            $password = (string) ($_POST['password'] ?? '');

            if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Name and a valid email address are required.');
            }

            if (!in_array($role, ['system_admin','deans_office_admin','department_chair','program_coordinator'], true)) {
                throw new RuntimeException('Choose a valid staff role.');
            }

            if ($password !== '' && strlen($password) < 12) {
                throw new RuntimeException('Local passwords must be at least 12 characters.');
            }

            [$firstName, $lastName] = $this->splitName($name);

            $this->pdo->beginTransaction();

            $stmt = $this->pdo->prepare(
                'INSERT INTO users (
                    public_id, person_type, staff_role, first_name, last_name,
                    display_name, email, hawkid, active
                 ) VALUES (UUID(), "staff", ?, ?, ?, ?, ?, ?, 1)'
            );
            $stmt->execute([
                $role,
                $firstName,
                $lastName,
                $name,
                $email,
                $hawkid !== '' ? $hawkid : null,
            ]);
            $userId = (int) $this->pdo->lastInsertId();

            if ($password !== '') {
                $identity = $this->pdo->prepare(
                    'INSERT INTO auth_identities (
                        user_id, provider, provider_subject, password_hash, activated_at
                     ) VALUES (?, "local", ?, ?, NOW())'
                );
                $identity->execute([
                    $userId,
                    strtolower($email),
                    password_hash($password, PASSWORD_ARGON2ID),
                ]);
            }

            $this->pdo->commit();

            $this->audit(
                'user.created',
                'user',
                $userId,
                $cycle ? (int) $cycle['id'] : null,
                null,
                ['display_name'=>$name,'email'=>$email,'staff_role'=>$role,'hawkid'=>$hawkid ?: null]
            );
            Flash::success('Staff user created.');
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            Flash::error($e->getMessage());
        }

        $this->redirect('/admin/users');
    }

    public function toggle(array $params): never
    {
        $this->requireAdmin();
        $this->requirePost();
        $cycle = $this->currentCycle();
        $userId = (int) ($params['id'] ?? 0);

        try {
            if ($userId === (int) $this->auth->userId()) {
                throw new RuntimeException('You cannot deactivate your own account.');
            }

            $stmt = $this->pdo->prepare(
                'UPDATE users SET active = CASE WHEN active = 1 THEN 0 ELSE 1 END WHERE id = ? AND person_type = "staff"'
            );
            $stmt->execute([$userId]);

            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('Staff user not found.');
            }

            $this->audit(
                'user.active_toggled',
                'user',
                $userId,
                $cycle ? (int) $cycle['id'] : null
            );
            Flash::success('Staff account status updated.');
        } catch (\Throwable $e) {
            Flash::error($e->getMessage());
        }

        $this->redirect('/admin/users');
    }

    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $first = array_shift($parts) ?: 'User';
        $last = $parts === [] ? 'User' : implode(' ', $parts);

        return [$first, $last];
    }

    private function csrf(): string
    {
        return View::csrfField();
    }
}

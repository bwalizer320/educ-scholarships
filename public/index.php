<?php

declare(strict_types=1);

use App\Auth\AuthService;
use App\Database\Connection;
use App\Http\Csrf;
use App\Http\Router;

$root = require dirname(__DIR__) . '/app/bootstrap.php';

$router = new Router();

$layout = static function (string $title, string $body, ?string $user = null): string {
    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $userHtml = $user
        ? '<span class="user">' . htmlspecialchars($user, ENT_QUOTES, 'UTF-8') . '</span>'
        : '';

    return <<<HTML
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{$safeTitle} | Scholarship Manager</title>
    <style>
        :root {
            font-family: Arial, Helvetica, sans-serif;
            color: #151515;
            background: #f4f4f4;
            --iowa-gold: #ffcd00;
            --border: #d7d7d7;
            --focus: #005ea8;
        }
        * { box-sizing: border-box; }
        body { margin: 0; line-height: 1.5; }
        .skip { position: absolute; left: -9999px; top: 0; background: #fff; color: #000; padding: .75rem; z-index: 10; }
        .skip:focus { left: .75rem; top: .75rem; }
        header { background: #000; color: #fff; border-bottom: 6px solid var(--iowa-gold); }
        .header-inner { max-width: 76rem; margin: 0 auto; padding: 1rem 1.25rem; display: flex; gap: 1rem; align-items: center; justify-content: space-between; }
        .brand { font-weight: 700; }
        .brand .iowa { color: var(--iowa-gold); letter-spacing: .06em; margin-right: .8rem; }
        .user { font-size: .9rem; }
        main { max-width: 76rem; margin: 2rem auto; padding: 0 1.25rem 3rem; }
        .card { background: #fff; border: 1px solid var(--border); border-radius: .5rem; padding: 1.5rem; box-shadow: 0 1px 2px rgba(0,0,0,.04); }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(15rem, 1fr)); gap: 1rem; margin-top: 1rem; }
        .stat { border: 1px solid var(--border); border-radius: .4rem; padding: 1rem; background: #fff; }
        .stat strong { display: block; font-size: 1.7rem; }
        label { display: block; font-weight: 700; margin-top: 1rem; }
        input { width: 100%; max-width: 28rem; padding: .7rem; border: 1px solid #777; border-radius: .25rem; font: inherit; }
        button, .button { display: inline-block; margin-top: 1rem; padding: .7rem 1rem; background: #111; color: #fff; border: 2px solid #111; border-radius: .25rem; font-weight: 700; text-decoration: none; cursor: pointer; }
        button:hover, .button:hover { background: #333; }
        a { color: #005ea8; }
        a:focus, button:focus, input:focus { outline: 3px solid var(--focus); outline-offset: 2px; }
        .error { border-left: 4px solid #b50909; padding: .75rem 1rem; background: #fff2f2; }
        .actions { display: flex; gap: .75rem; flex-wrap: wrap; }
        .actions form { margin: 0; }
        .actions button { margin-top: 0; }
    </style>
</head>
<body>
<a class="skip" href="#main">Skip to main content</a>
<header>
    <div class="header-inner">
        <div class="brand"><span class="iowa">IOWA</span> College of Education Scholarship Manager</div>
        {$userHtml}
    </div>
</header>
<main id="main">
{$body}
</main>
</body>
</html>
HTML;
};

$auth = static fn(): AuthService => new AuthService(Connection::get());

$router->get('/', static function () use ($auth): never {
    header('Location: ' . ($auth()->check() ? '/dashboard' : '/login'));
    exit;
});

$router->get('/login', static function () use ($layout, $auth): string {
    if ($auth()->check()) {
        header('Location: /dashboard');
        exit;
    }

    $token = htmlspecialchars(Csrf::token(), ENT_QUOTES, 'UTF-8');

    $body = <<<HTML
<section class="card" aria-labelledby="login-title">
    <h1 id="login-title">Sign in</h1>
    <p>Use the local development account. Production authentication will use HawkID/SSO when configured.</p>
    <form method="post" action="/login">
        <input type="hidden" name="_csrf" value="{$token}">
        <label for="email">Email</label>
        <input id="email" name="email" type="email" autocomplete="username" required>
        <label for="password">Password</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required>
        <button type="submit">Sign in</button>
    </form>
</section>
HTML;

    return $layout('Sign in', $body);
});

$router->post('/login', static function () use ($layout, $auth): string {
    try {
        Csrf::assertValid($_POST['_csrf'] ?? null);
    } catch (Throwable) {
        http_response_code(419);
        return $layout('Session expired', '<div class="error"><h1>Session expired</h1><p>Return to the sign-in page and try again.</p></div>');
    }

    if ($auth()->attempt((string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''))) {
        header('Location: /dashboard');
        exit;
    }

    http_response_code(422);
    $token = htmlspecialchars(Csrf::token(), ENT_QUOTES, 'UTF-8');

    $body = <<<HTML
<div class="error" role="alert"><strong>Sign-in failed.</strong> Check the email and password.</div>
<section class="card" aria-labelledby="login-title">
    <h1 id="login-title">Sign in</h1>
    <form method="post" action="/login">
        <input type="hidden" name="_csrf" value="{$token}">
        <label for="email">Email</label>
        <input id="email" name="email" type="email" autocomplete="username" required>
        <label for="password">Password</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required>
        <button type="submit">Sign in</button>
    </form>
</section>
HTML;

    return $layout('Sign in', $body);
});

$router->post('/logout', static function () use ($auth): never {
    Csrf::assertValid($_POST['_csrf'] ?? null);
    $auth()->logout();
    header('Location: /login');
    exit;
});

$router->get('/dashboard', static function () use ($layout, $auth): string {
    $service = $auth();

    if (!$service->check()) {
        header('Location: /login');
        exit;
    }

    $pdo = Connection::get();
    $cycle = $pdo->query("SELECT id, label, status FROM academic_cycles WHERE is_current = 1 LIMIT 1")->fetch();

    $cycleLabel = $cycle
        ? htmlspecialchars((string) $cycle['label'], ENT_QUOTES, 'UTF-8')
        : 'No current cycle';

    $cycleStatus = $cycle
        ? htmlspecialchars(str_replace('_', ' ', (string) $cycle['status']), ENT_QUOTES, 'UTF-8')
        : 'setup required';

    $scholarships = (int) $pdo->query('SELECT COUNT(*) FROM scholarships WHERE active = 1')->fetchColumn();
    $programs = (int) $pdo->query("SELECT COUNT(*) FROM org_units WHERE unit_type = 'program' AND active = 1")->fetchColumn();
    $token = htmlspecialchars(Csrf::token(), ENT_QUOTES, 'UTF-8');

    $body = <<<HTML
<div class="actions" style="justify-content:space-between;align-items:center">
    <div>
        <h1>Scholarship Manager</h1>
        <p><strong>{$cycleLabel}</strong> &middot; {$cycleStatus}</p>
    </div>
    <form method="post" action="/logout">
        <input type="hidden" name="_csrf" value="{$token}">
        <button type="submit">Sign out</button>
    </form>
</div>
<div class="grid" aria-label="System summary">
    <section class="stat"><span>Active scholarships</span><strong>{$scholarships}</strong></section>
    <section class="stat"><span>Canonical programs</span><strong>{$programs}</strong></section>
    <section class="stat"><span>Current cycle</span><strong>{$cycleLabel}</strong></section>
</div>
<section class="card" style="margin-top:1rem">
    <h2>Foundation status</h2>
    <p>The core annual planning, applicant review, eligibility, renewal, enrollment, award, notification, and thank-you data model is now installed in the codebase. Administrative workflow screens are next.</p>
</section>
HTML;

    return $layout('Dashboard', $body, $service->displayName());
});

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$response = $router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $path);

if (is_string($response)) {
    echo $response;
}

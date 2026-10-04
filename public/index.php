<?php

declare(strict_types=1);

use App\Http\Router;

$root = require dirname(__DIR__) . '/app/bootstrap.php';

$router = new Router();

$router->get('/', static function (): string {
    $title = 'College of Education Scholarship Manager';

    return <<<HTML
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{$title}</title>
    <style>
        :root { font-family: Arial, Helvetica, sans-serif; color: #151515; background: #f5f5f5; }
        body { margin: 0; }
        header { background: #000; color: #fff; border-bottom: 6px solid #ffcd00; padding: 1rem 1.25rem; }
        header strong { color: #ffcd00; }
        main { max-width: 72rem; margin: 2rem auto; padding: 0 1.25rem; }
        .card { background: #fff; border: 1px solid #d9d9d9; border-radius: .5rem; padding: 1.5rem; }
        a:focus, button:focus, input:focus { outline: 3px solid #005ea8; outline-offset: 2px; }
    </style>
</head>
<body>
<a href="#main" style="position:absolute;left:-9999px">Skip to main content</a>
<header>
    <strong>IOWA</strong> &nbsp; College of Education
</header>
<main id="main">
    <section class="card" aria-labelledby="page-title">
        <h1 id="page-title">Scholarship Manager</h1>
        <p>Phase 1 foundation is installed. Authentication, academic cycles, program architecture, and scholarship planning are being built next.</p>
    </section>
</main>
</body>
</html>
HTML;
});

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$response = $router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $path);

if (is_string($response)) {
    echo $response;
}

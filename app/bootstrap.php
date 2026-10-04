<?php

declare(strict_types=1);

use App\Support\Env;

$root = dirname(__DIR__);

if (is_file($root . '/vendor/autoload.php')) {
    require $root . '/vendor/autoload.php';
} else {
    spl_autoload_register(static function (string $class) use ($root): void {
        $prefix = 'App\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }

        $relative = substr($class, strlen($prefix));
        $path = $root . '/app/' . str_replace('\\', '/', $relative) . '.php';

        if (is_file($path)) {
            require $path;
        }
    });
}

Env::load($root . '/.env');

date_default_timezone_set(Env::get('APP_TIMEZONE', 'America/Chicago') ?? 'America/Chicago');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('educ_scholarships');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => Env::bool('SESSION_SECURE', false),
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
}

return $root;

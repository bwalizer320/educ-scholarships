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

$environment = Env::get('APP_ENV', 'development') ?? 'development';
date_default_timezone_set(Env::get('APP_TIMEZONE', 'America/Chicago') ?? 'America/Chicago');

if ($environment === 'production') {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');

    $appUrl = Env::get('APP_URL', '') ?? '';
    if (!str_starts_with(strtolower($appUrl), 'https://')) {
        throw new RuntimeException('APP_URL must use HTTPS in production.');
    }

    if (!Env::bool('SESSION_SECURE', false)) {
        throw new RuntimeException('SESSION_SECURE must be true in production.');
    }

    if (trim((string) (Env::get('DB_PASSWORD', '') ?? '')) === '') {
        throw new RuntimeException('DB_PASSWORD must be configured in production.');
    }
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');

    session_name('educ_scholarships');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => Env::bool('SESSION_SECURE', $environment === 'production'),
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();

    if (!isset($_SESSION['_started_at'])) {
        session_regenerate_id(true);
        $_SESSION['_started_at'] = time();
    }

    $maxLifetime = (int) (Env::get('SESSION_MAX_LIFETIME_SECONDS', '28800') ?? '28800');
    $lastActivity = (int) ($_SESSION['_last_activity'] ?? time());

    if ($maxLifetime > 0 && time() - $lastActivity > $maxLifetime) {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['_started_at'] = time();
    }

    $_SESSION['_last_activity'] = time();
}

return $root;

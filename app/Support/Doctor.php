<?php

declare(strict_types=1);

namespace App\Support;

use App\Database\Connection;
use RuntimeException;

final class Doctor
{
    public static function run(string $root): array
    {
        $checks = [];

        $checks['php_version'] = [
            'ok' => version_compare(PHP_VERSION, '8.5.0', '>='),
            'detail' => PHP_VERSION,
        ];

        foreach (['pdo_mysql','mbstring','dom','zip','fileinfo'] as $extension) {
            $checks['ext_' . $extension] = [
                'ok' => extension_loaded($extension),
                'detail' => extension_loaded($extension) ? 'loaded' : 'missing',
            ];
        }

        try {
            Connection::get()->query('SELECT 1')->fetchColumn();
            $checks['database'] = ['ok'=>true,'detail'=>'connected'];
        } catch (\Throwable $e) {
            $checks['database'] = ['ok'=>false,'detail'=>$e->getMessage()];
        }

        $storage = $root . '/' . trim(Env::get('LOCAL_STORAGE_PATH','storage/files') ?? 'storage/files','/');
        $checks['storage'] = [
            'ok' => is_dir($storage) && is_writable($storage),
            'detail' => $storage,
        ];

        $logDir = $root . '/storage/logs';
        $checks['logs'] = [
            'ok' => is_dir($logDir) && is_writable($logDir),
            'detail' => $logDir,
        ];

        $environment = Env::get('APP_ENV','development') ?? 'development';
        $checks['environment'] = [
            'ok' => in_array($environment,['development','testing','production'],true),
            'detail' => $environment,
        ];

        if ($environment === 'production') {
            $url = Env::get('APP_URL','') ?? '';
            $checks['https_url'] = [
                'ok' => str_starts_with(strtolower($url),'https://'),
                'detail' => $url,
            ];
            $checks['secure_session'] = [
                'ok' => Env::bool('SESSION_SECURE',false),
                'detail' => Env::bool('SESSION_SECURE',false) ? 'enabled' : 'disabled',
            ];
        }

        return $checks;
    }
}

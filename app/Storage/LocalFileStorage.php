<?php

declare(strict_types=1);

namespace App\Storage;

use App\Support\Env;
use RuntimeException;

final class LocalFileStorage
{
    public function root(): string
    {
        $configured = Env::get('LOCAL_STORAGE_PATH', 'storage/files') ?? 'storage/files';

        if (str_starts_with($configured, '/')) {
            return rtrim($configured, '/');
        }

        return dirname(__DIR__, 2) . '/' . trim($configured, '/');
    }

    public function storeUploaded(string $tmpPath, string $originalName, string $folder = 'imports'): array
    {
        if (!is_file($tmpPath)) {
            throw new RuntimeException('Uploaded file is not available.');
        }

        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $safeExtension = preg_replace('/[^a-z0-9]/', '', $extension) ?: 'bin';
        $key = trim($folder, '/') . '/' . date('Y/m') . '/' . bin2hex(random_bytes(16)) . '.' . $safeExtension;
        $destination = $this->root() . '/' . $key;

        $directory = dirname($destination);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create application storage directory.');
        }

        $moved = is_uploaded_file($tmpPath)
            ? move_uploaded_file($tmpPath, $destination)
            : copy($tmpPath, $destination);

        if (!$moved) {
            throw new RuntimeException('Could not store uploaded file.');
        }

        $mime = mime_content_type($destination) ?: 'application/octet-stream';

        return [
            'storage_driver' => 'local',
            'storage_key' => $key,
            'path' => $destination,
            'original_filename' => $originalName,
            'mime_type' => $mime,
            'size_bytes' => filesize($destination) ?: 0,
            'sha256' => hash_file('sha256', $destination),
        ];
    }

    public function path(string $storageKey): string
    {
        $path = $this->root() . '/' . ltrim($storageKey, '/');

        if (!is_file($path)) {
            throw new RuntimeException('Stored file could not be found.');
        }

        return $path;
    }
}

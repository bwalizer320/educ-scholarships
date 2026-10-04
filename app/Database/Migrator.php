<?php

declare(strict_types=1);

namespace App\Database;

use PDO;
use RuntimeException;

final class Migrator
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $migrationPath
    ) {
    }

    public function migrate(): array
    {
        $this->ensureMigrationTable();

        $applied = $this->pdo
            ->query('SELECT migration FROM schema_migrations ORDER BY migration')
            ->fetchAll(PDO::FETCH_COLUMN);

        $files = glob(rtrim($this->migrationPath, '/') . '/*.sql') ?: [];
        sort($files, SORT_STRING);

        $ran = [];

        foreach ($files as $file) {
            $migration = basename($file);

            if (in_array($migration, $applied, true)) {
                continue;
            }

            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new RuntimeException("Could not read migration {$migration}");
            }

            $this->pdo->beginTransaction();

            try {
                $this->pdo->exec($sql);
                $stmt = $this->pdo->prepare(
                    'INSERT INTO schema_migrations (migration, applied_at) VALUES (?, NOW())'
                );
                $stmt->execute([$migration]);
                $this->pdo->commit();
                $ran[] = $migration;
            } catch (\Throwable $e) {
                $this->pdo->rollBack();
                throw $e;
            }
        }

        return $ran;
    }

    private function ensureMigrationTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                migration VARCHAR(255) NOT NULL UNIQUE,
                applied_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
}

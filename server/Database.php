<?php

declare(strict_types=1);

namespace Statington\Server;

use PDO;

final class Database
{
    private ?PDO $pdo = null;
    private string $path;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? __DIR__ . '/storage/statington.sqlite';
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $this->connect();
        if ($this->hasLegacySchema()) {
            $this->backupLegacyDatabase();
            $this->connect();
        }

        $this->pdo()->exec((string) file_get_contents(__DIR__ . '/schema.sql'));
        $this->migrate();
    }

    public function pdo(): PDO
    {
        if (!$this->pdo instanceof PDO) {
            $this->connect();
        }

        return $this->pdo;
    }

    private function connect(): void
    {
        $this->pdo = new PDO('sqlite:' . $this->path);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    private function hasLegacySchema(): bool
    {
        $pdo = $this->pdo();
        $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'events'");
        if ($stmt->fetchColumn() === false) {
            return false;
        }

        $eventColumns = $this->columns('events');
        $requestColumns = $this->columns('requests');

        return ($eventColumns['id'] ?? null) !== 'INTEGER'
            || !array_key_exists('status', $requestColumns)
            || array_key_exists('status_code', $requestColumns);
    }

    private function columns(string $table): array
    {
        $columns = [];
        $stmt = $this->pdo()->query('PRAGMA table_info(' . $table . ')');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
            $columns[(string) $column['name']] = strtoupper((string) $column['type']);
        }

        return $columns;
    }

    private function backupLegacyDatabase(): void
    {
        $this->pdo = null;
        $backup = $this->path . '.legacy.' . date('YmdHis');
        @rename($this->path, $backup);
    }

    private function migrate(): void
    {
        $columns = $this->columns('database_events');
        if ($columns !== [] && !array_key_exists('driver', $columns)) {
            $this->pdo()->exec('ALTER TABLE database_events ADD COLUMN driver TEXT NULL');
        }

        $sourceColumns = [
            'source_file' => 'TEXT NULL',
            'source_line' => 'INTEGER NULL',
            'source_class' => 'TEXT NULL',
            'source_function' => 'TEXT NULL',
            'source_confidence' => 'TEXT NULL',
            'is_slow' => 'INTEGER DEFAULT 0',
            'is_error' => 'INTEGER DEFAULT 0',
            'error_class' => 'TEXT NULL',
            'error_message' => 'TEXT NULL',
            'error_code' => 'TEXT NULL',
        ];

        foreach ($sourceColumns as $column => $type) {
            if ($columns !== [] && !array_key_exists($column, $columns)) {
                $this->pdo()->exec(sprintf('ALTER TABLE database_events ADD COLUMN %s %s', $column, $type));
            }
        }
    }
}

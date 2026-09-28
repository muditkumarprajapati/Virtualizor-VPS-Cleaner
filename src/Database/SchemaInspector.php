<?php
/**
 * Virtualizor VPS Cleanup Manager
 *
 * Dynamic Database Schema Inspector and MyISAM Engine Detector
 *
 * @package VirtualizorVpsCleaner\Database
 * @author  Mudit Kumar Prajapati
 * @license MIT
 */

namespace VirtualizorVpsCleaner\Database;

use PDO;
use RuntimeException;

class SchemaInspector
{
    private Connection $connection;
    private ?array $tables = null;
    private ?array $tableEngines = null;
    private array $columnCache = [];

    /**
     * Required core tables for cleanup operations
     */
    public const REQUIRED_TABLES = ['vps', 'disks', 'ips'];

    /**
     * Required columns per table
     */
    public const REQUIRED_COLUMNS = [
        'vps'   => ['vpsid', 'vps_name', 'uuid', 'serid'],
        'disks' => ['did', 'vps_uuid', 'path'],
        'ips'   => ['ipid', 'vpsid', 'ip'],
    ];

    /**
     * Constructor
     */
    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Get all tables in current database
     *
     * @return array<string>
     */
    public function getTables(): array
    {
        if ($this->tables !== null) {
            return $this->tables;
        }

        $pdo = $this->connection->getPdo();
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
            $this->tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } else {
            $stmt = $pdo->query("SHOW TABLES");
            $this->tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }

        return $this->tables;
    }

    /**
     * Check if a specific table exists
     */
    public function hasTable(string $table): bool
    {
        $tables = array_map('strtolower', $this->getTables());
        return in_array(strtolower($table), $tables, true);
    }

    /**
     * Get storage engine for all tables
     *
     * @return array<string, string> [tableName => engine]
     */
    public function getTableEngines(): array
    {
        if ($this->tableEngines !== null) {
            return $this->tableEngines;
        }

        $pdo = $this->connection->getPdo();
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $this->tableEngines = [];

        if ($driver === 'sqlite') {
            foreach ($this->getTables() as $tbl) {
                $this->tableEngines[$tbl] = 'SQLite';
            }
            return $this->tableEngines;
        }

        try {
            $stmt = $pdo->query("SHOW TABLE STATUS");
            while ($row = $stmt->fetch()) {
                $name = $row['Name'] ?? $row['name'] ?? null;
                $engine = $row['Engine'] ?? $row['engine'] ?? 'Unknown';
                if ($name !== null) {
                    $this->tableEngines[$name] = $engine;
                }
            }
        } catch (\Throwable $e) {
            // Fallback if permissions restrict SHOW TABLE STATUS
            foreach ($this->getTables() as $t) {
                $this->tableEngines[$t] = 'Unknown';
            }
        }

        return $this->tableEngines;
    }

    /**
     * Check if any target table uses the MyISAM engine
     *
     * @param array<string>|null $tables
     * @return array<string> Array of table names using MyISAM
     */
    public function getMyIsamTables(?array $tables = null): array
    {
        $engines = $this->getTableEngines();
        $targetTables = $tables ?? self::REQUIRED_TABLES;
        $myIsam = [];

        foreach ($targetTables as $tbl) {
            foreach ($engines as $name => $eng) {
                if (strcasecmp($name, $tbl) === 0 && strcasecmp($eng, 'MyISAM') === 0) {
                    $myIsam[] = $name;
                }
            }
        }

        return $myIsam;
    }

    /**
     * Get columns for a given table
     *
     * @param string $table
     * @return array<string> List of lowercase column names
     */
    public function getColumns(string $table): array
    {
        $cacheKey = strtolower($table);
        if (isset($this->columnCache[$cacheKey])) {
            return $this->columnCache[$cacheKey];
        }

        $pdo = $this->connection->getPdo();
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $cols = [];

        if ($driver === 'sqlite') {
            $stmt = $pdo->query("PRAGMA table_info(`{$table}`)");
            while ($row = $stmt->fetch()) {
                $cols[] = strtolower($row['name']);
            }
        } else {
            $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
            while ($row = $stmt->fetch()) {
                $field = $row['Field'] ?? $row['field'] ?? '';
                if ($field !== '') {
                    $cols[] = strtolower($field);
                }
            }
        }

        $this->columnCache[$cacheKey] = $cols;
        return $cols;
    }

    /**
     * Check if a column exists in a table
     */
    public function hasColumn(string $table, string $column): bool
    {
        $cols = $this->getColumns($table);
        return in_array(strtolower($column), $cols, true);
    }

    /**
     * Validate database schema against minimum requirements
     *
     * @return array{valid: bool, errors: array<string>, warnings: array<string>}
     */
    public function validateSchema(): array
    {
        $errors = [];
        $warnings = [];

        // 1. Check required tables
        foreach (self::REQUIRED_TABLES as $tbl) {
            if (!$this->hasTable($tbl)) {
                $errors[] = "Required table '{$tbl}' does not exist in the database.";
            } else {
                // Check required columns
                $requiredCols = self::REQUIRED_COLUMNS[$tbl] ?? [];
                $existingCols = $this->getColumns($tbl);
                foreach ($requiredCols as $col) {
                    if (!in_array(strtolower($col), $existingCols, true)) {
                        $errors[] = "Required column '{$col}' is missing in table '{$tbl}'.";
                    }
                }
            }
        }

        // 2. Check MyISAM warnings
        $myIsam = $this->getMyIsamTables();
        if (!empty($myIsam)) {
            $warnings[] = "Tables (" . implode(', ', $myIsam) . ") are using the MyISAM storage engine. " .
                          "SQL ROLLBACK is NOT supported! Full backup and table locking are mandatory.";
        }

        // 3. Check optional related tables
        if (!$this->hasTable('servers')) {
            $warnings[] = "Table 'servers' was not detected. Server node names will be displayed as server IDs.";
        }
        if (!$this->hasTable('tasks')) {
            $warnings[] = "Table 'tasks' was not detected. Active task lock checking will be bypassed.";
        }

        return [
            'valid'    => empty($errors),
            'errors'   => $errors,
            'warnings' => $warnings,
        ];
    }
}

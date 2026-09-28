<?php
/**
 * Virtualizor VPS Cleanup Manager
 *
 * Secure Database Connection and Table Lock Manager
 *
 * @package VirtualizorVpsCleaner\Database
 * @author  Mudit Kumar Prajapati
 * @license MIT
 */

namespace VirtualizorVpsCleaner\Database;

use PDO;
use PDOException;
use RuntimeException;
use VirtualizorVpsCleaner\Config\VirtualizorConfig;

class Connection
{
    private VirtualizorConfig $config;
    private ?PDO $pdo = null;
    private bool $tablesLocked = false;
    private array $lockedTables = [];

    /**
     * Constructor
     */
    public function __construct(VirtualizorConfig $config)
    {
        $this->config = $config;

        // Register shutdown function to guarantee tables are unlocked even on fatal errors or exit
        register_shutdown_function(function () {
            if ($this->tablesLocked) {
                $this->unlockTables();
            }
        });
    }

    /**
     * Destructor: guarantees locks are released if connection closes
     */
    public function __destruct()
    {
        if ($this->tablesLocked) {
            $this->unlockTables();
        }
    }

    /**
     * Force unlock all tables on the database server regardless of tracked state
     */
    public function forceUnlockTables(): bool
    {
        if ($this->config->getDriver() === 'sqlite') {
            $this->tablesLocked = false;
            $this->lockedTables = [];
            return true;
        }

        try {
            $this->getPdo()->exec("UNLOCK TABLES");
        } catch (\Throwable $e) {
            // Ignore if connection already dropped
        }

        $this->tablesLocked = false;
        $this->lockedTables = [];
        return true;
    }

    /**
     * Get or initialize PDO connection
     *
     * @return PDO
     * @throws RuntimeException
     */
    public function getPdo(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        $driver = $this->config->getDriver();

        try {
            if ($driver === 'sqlite') {
                $path = $this->config->getSqlitePath() ?: ':memory:';
                $dsn = "sqlite:{$path}";
                $this->pdo = new PDO($dsn, null, null, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);
            } else {
                // MySQL / MariaDB
                $host = $this->config->getDbHost();
                $port = $this->config->getDbPort();
                $dbName = $this->config->getDbName();
                $socket = $this->config->getDbSocket();

                if (!empty($socket) && file_exists($socket)) {
                    $dsn = "mysql:unix_socket={$socket};dbname={$dbName};charset=utf8mb4";
                } else {
                    $dsn = "mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4";
                }

                $this->pdo = new PDO(
                    $dsn,
                    $this->config->getDbUser(),
                    $this->config->getDbPass(),
                    [
                        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES   => false,
                        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
                    ]
                );
            }
        } catch (PDOException $e) {
            // NEVER reveal database credentials in the exception message!
            $safeMsg = "Database connection failed to {$this->config->getDbHost()}:{$this->config->getDbPort()} [DB: {$this->config->getDbName()}]. ";
            $safeMsg .= "Error: " . $this->sanitizeError($e->getMessage());
            throw new RuntimeException($safeMsg, (int) $e->getCode(), $e);
        }

        return $this->pdo;
    }

    /**
     * Test if database connection is alive
     */
    public function ping(): bool
    {
        try {
            $pdo = $this->getPdo();
            $stmt = $pdo->query('SELECT 1');
            return ($stmt !== false && $stmt->fetchColumn() !== false);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Lock tables for safe MyISAM modification
     *
     * @param array<string, string> $tables [tableName => 'WRITE'|'READ']
     * @return bool
     */
    public function lockTables(array $tables): bool
    {
        if ($this->config->getDriver() === 'sqlite') {
            $this->tablesLocked = true;
            $this->lockedTables = array_keys($tables);
            return true;
        }

        $lockStatements = [];
        foreach ($tables as $table => $mode) {
            $mode = strtoupper($mode) === 'WRITE' ? 'WRITE' : 'READ';
            $lockStatements[] = "`{$table}` {$mode}";
        }

        $sql = "LOCK TABLES " . implode(', ', $lockStatements);

        try {
            $this->getPdo()->exec($sql);
            $this->tablesLocked = true;
            $this->lockedTables = array_keys($tables);
            return true;
        } catch (PDOException $e) {
            throw new RuntimeException("Failed to acquire table locks: " . $this->sanitizeError($e->getMessage()), (int) $e->getCode(), $e);
        }
    }

    /**
     * Unlock all locked tables
     *
     * @return bool
     */
    public function unlockTables(): bool
    {
        if (!$this->tablesLocked) {
            return true;
        }

        if ($this->config->getDriver() === 'sqlite') {
            $this->tablesLocked = false;
            $this->lockedTables = [];
            return true;
        }

        try {
            $this->getPdo()->exec("UNLOCK TABLES");
            $this->tablesLocked = false;
            $this->lockedTables = [];
            return true;
        } catch (PDOException $e) {
            // Still mark as released to prevent infinite loops, but report error
            $this->tablesLocked = false;
            throw new RuntimeException("Failed to unlock tables: " . $this->sanitizeError($e->getMessage()), (int) $e->getCode(), $e);
        }
    }

    /**
     * Are tables currently locked?
     */
    public function areTablesLocked(): bool
    {
        return $this->tablesLocked;
    }

    /**
     * Get list of currently locked tables
     *
     * @return array<string>
     */
    public function getLockedTables(): array
    {
        return $this->lockedTables;
    }

    /**
     * Sanitize error message to ensure passwords are removed
     */
    public function sanitizeError(string $message): string
    {
        $pass = $this->config->getDbPass();
        if (!empty($pass)) {
            $message = str_replace($pass, '********', $message);
        }
        return preg_replace('/password=[^;\s&]+/i', 'password=********', $message) ?? $message;
    }
}

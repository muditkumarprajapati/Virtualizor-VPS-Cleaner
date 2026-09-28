<?php
/**
 * Virtualizor VPS Cleanup Manager
 *
 * Configuration loader and manager
 *
 * @package VirtualizorVpsCleaner\Config
 * @author  Mudit Kumar Prajapati
 * @license MIT
 */

namespace VirtualizorVpsCleaner\Config;

use RuntimeException;
use InvalidArgumentException;

class VirtualizorConfig
{
    /**
     * Default Virtualizor configuration file on Linux
     */
    public const DEFAULT_UNIVERSAL_PATH = '/usr/local/virtualizor/universal.php';

    /**
     * Default Virtualizor EMPS PHP executable
     */
    public const DEFAULT_EMPS_PHP = '/usr/local/emps/bin/php';

    /**
     * Database driver ('mysql' or 'sqlite' for tests)
     */
    private string $driver = 'mysql';

    /**
     * Database host
     */
    private string $dbHost = 'localhost';

    /**
     * Database port
     */
    private int $dbPort = 3306;

    /**
     * Database user
     */
    private string $dbUser = 'root';

    /**
     * Database password
     */
    private string $dbPass = '';

    /**
     * Database name
     */
    private string $dbName = 'virtualizor';

    /**
     * Database UNIX socket (if any)
     */
    private ?string $dbSocket = null;

    /**
     * SQLite path (only used if driver is sqlite)
     */
    private ?string $sqlitePath = null;

    /**
     * Backup directory
     */
    private string $backupDir = '';

    /**
     * Log directory
     */
    private string $logDir = '';

    /**
     * Path from which configuration was loaded
     */
    private ?string $loadedFromPath = null;

    /**
     * Backup retention count (never deletes automatically without permission, but recommends count)
     */
    private int $backupRetention = 30;

    /**
     * Constructor
     *
     * @param array<string, mixed> $options
     */
    public function __construct(array $options = [])
    {
        if (isset($options['driver'])) {
            $this->driver = (string) $options['driver'];
        }
        if (isset($options['host'])) {
            $this->dbHost = (string) $options['host'];
        }
        if (isset($options['port'])) {
            $this->dbPort = (int) $options['port'];
        }
        if (isset($options['user'])) {
            $this->dbUser = (string) $options['user'];
        }
        if (isset($options['pass'])) {
            $this->dbPass = (string) $options['pass'];
        }
        if (isset($options['name'])) {
            $this->dbName = (string) $options['name'];
        }
        if (isset($options['socket'])) {
            $this->dbSocket = (string) $options['socket'];
        }
        if (isset($options['sqlite_path'])) {
            $this->sqlitePath = (string) $options['sqlite_path'];
        }
        if (isset($options['backup_dir'])) {
            $this->backupDir = (string) $options['backup_dir'];
        }
        if (isset($options['log_dir'])) {
            $this->logDir = (string) $options['log_dir'];
        }
        if (isset($options['loaded_from'])) {
            $this->loadedFromPath = (string) $options['loaded_from'];
        }
        if (isset($options['backup_retention'])) {
            $this->backupRetention = (int) $options['backup_retention'];
        }

        $this->resolveDefaultPaths();
    }

    /**
     * Load configuration from Virtualizor's universal.php file or environment variables
     *
     * @param string|null $customConfigPath Optional custom path
     * @return self
     * @throws RuntimeException If configuration cannot be located or parsed
     */
    public static function load(?string $customConfigPath = null): self
    {
        $options = [];

        // 1. Check for custom config path or environment variable
        $candidatePaths = [];
        if ($customConfigPath !== null) {
            $candidatePaths[] = $customConfigPath;
        }

        $envConfig = getenv('VIRTUALIZOR_CONF');
        if ($envConfig !== false && !empty($envConfig)) {
            $candidatePaths[] = $envConfig;
        }

        $candidatePaths[] = self::DEFAULT_UNIVERSAL_PATH;

        $loadedPath = null;
        foreach ($candidatePaths as $path) {
            if (file_exists($path) && is_readable($path)) {
                $loadedPath = $path;
                break;
            }
        }

        if ($loadedPath !== null) {
            $options = self::parseUniversalFile($loadedPath);
            $options['loaded_from'] = $loadedPath;
        }

        // 2. Allow environment variable overrides (never overriding with empty values)
        if (($envDriver = getenv('VIRTUALIZOR_DB_DRIVER')) !== false && $envDriver !== '') {
            $options['driver'] = $envDriver;
        }
        if (($envHost = getenv('VIRTUALIZOR_DB_HOST')) !== false && $envHost !== '') {
            $options['host'] = $envHost;
        }
        if (($envPort = getenv('VIRTUALIZOR_DB_PORT')) !== false && $envPort !== '') {
            $options['port'] = (int) $envPort;
        }
        if (($envUser = getenv('VIRTUALIZOR_DB_USER')) !== false && $envUser !== '') {
            $options['user'] = $envUser;
        }
        if (($envPass = getenv('VIRTUALIZOR_DB_PASS')) !== false) {
            $options['pass'] = $envPass;
        }
        if (($envName = getenv('VIRTUALIZOR_DB_NAME')) !== false && $envName !== '') {
            $options['name'] = $envName;
        }
        if (($envSocket = getenv('VIRTUALIZOR_DB_SOCKET')) !== false && $envSocket !== '') {
            $options['socket'] = $envSocket;
        }
        if (($envSqlite = getenv('VIRTUALIZOR_SQLITE_PATH')) !== false && $envSqlite !== '') {
            $options['sqlite_path'] = $envSqlite;
            $options['driver'] = 'sqlite';
        }
        if (($envBackupDir = getenv('VIRTUALIZOR_BACKUP_DIR')) !== false && $envBackupDir !== '') {
            $options['backup_dir'] = $envBackupDir;
        }
        if (($envLogDir = getenv('VIRTUALIZOR_LOG_DIR')) !== false && $envLogDir !== '') {
            $options['log_dir'] = $envLogDir;
        }

        if ($loadedPath === null && empty($options['host']) && empty($options['sqlite_path'])) {
            throw new RuntimeException(
                "Unable to locate Virtualizor configuration file (checked: " . implode(', ', $candidatePaths) . "). " .
                "Please provide a valid config file via --config or set environment variables."
            );
        }

        return new self($options);
    }

    /**
     * Parses Virtualizor's universal.php file securely in an isolated scope
     *
     * @param string $filePath
     * @return array<string, mixed>
     */
    public static function parseUniversalFile(string $filePath): array
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new InvalidArgumentException("Configuration file does not exist or is not readable: {$filePath}");
        }

        // Execute in an isolated function to prevent global pollution
        $parser = function ($file) {
            $globals = [];
            // Some configurations define $dbhost, $dbuser, etc. directly
            $dbhost = null;
            $dbuser = null;
            $dbpass = null;
            $dbname = null;
            $dbport = null;
            $dbsocket = null;

            // Suppress warnings in case file references undefined internal functions
            @include $file;

            return [
                'globals'  => isset($globals) && is_array($globals) ? $globals : [],
                'dbhost'   => $dbhost,
                'dbuser'   => $dbuser,
                'dbpass'   => $dbpass,
                'dbname'   => $dbname,
                'dbport'   => $dbport,
                'dbsocket' => $dbsocket,
            ];
        };

        $extracted = $parser($filePath);
        $g = $extracted['globals'];

        $config = [];

        // Check $globals first (standard Virtualizor structure)
        $config['host'] = $g['dbhost'] ?? $extracted['dbhost'] ?? 'localhost';
        $config['user'] = $g['dbuser'] ?? $extracted['dbuser'] ?? 'root';
        $config['pass'] = $g['dbpass'] ?? $extracted['dbpass'] ?? '';
        $config['name'] = $g['dbname'] ?? $extracted['dbname'] ?? 'virtualizor';
        $config['port'] = (int) ($g['dbport'] ?? $extracted['dbport'] ?? 3306);
        $config['socket'] = $g['dbsocket'] ?? $extracted['dbsocket'] ?? null;

        // Custom backup and log directories if configured
        if (!empty($g['backup_dir'])) {
            $config['backup_dir'] = $g['backup_dir'];
        }
        if (!empty($g['log_dir'])) {
            $config['log_dir'] = $g['log_dir'];
        }

        return $config;
    }

    /**
     * Resolve default backup and log directories based on OS and permissions
     */
    private function resolveDefaultPaths(): void
    {
        $isWindows = (DIRECTORY_SEPARATOR === '\\');

        // Backup directory resolution
        if (empty($this->backupDir)) {
            if ($isWindows) {
                $this->backupDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'backups';
            } else {
                // If running as root or can write to /var/backups
                if (is_dir('/var/backups') && is_writable('/var/backups')) {
                    $this->backupDir = '/var/backups/virtualizor-vps-cleaner';
                } elseif (is_dir('/usr/local/virtualizor') && is_writable('/usr/local/virtualizor')) {
                    $this->backupDir = '/usr/local/virtualizor/cleaner_backups';
                } else {
                    $this->backupDir = dirname(__DIR__, 2) . '/backups';
                }
            }
        }

        // Log directory resolution
        if (empty($this->logDir)) {
            if ($isWindows) {
                $this->logDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'logs';
            } else {
                if (is_dir('/var/log') && is_writable('/var/log')) {
                    $this->logDir = '/var/log/virtualizor-vps-cleaner';
                } elseif (is_dir('/usr/local/virtualizor') && is_writable('/usr/local/virtualizor')) {
                    $this->logDir = '/usr/local/virtualizor/cleaner_logs';
                } else {
                    $this->logDir = dirname(__DIR__, 2) . '/logs';
                }
            }
        }
    }

    /**
     * Get database driver
     */
    public function getDriver(): string
    {
        return $this->driver;
    }

    /**
     * Get database host
     */
    public function getDbHost(): string
    {
        return $this->dbHost;
    }

    /**
     * Get database port
     */
    public function getDbPort(): int
    {
        return $this->dbPort;
    }

    /**
     * Get database username
     */
    public function getDbUser(): string
    {
        return $this->dbUser;
    }

    /**
     * Get database password (RAW - only for PDO connection!)
     */
    public function getDbPass(): string
    {
        return $this->dbPass;
    }

    /**
     * Get database name
     */
    public function getDbName(): string
    {
        return $this->dbName;
    }

    /**
     * Get database UNIX socket
     */
    public function getDbSocket(): ?string
    {
        return $this->dbSocket;
    }

    /**
     * Get SQLite path
     */
    public function getSqlitePath(): ?string
    {
        return $this->sqlitePath;
    }

    /**
     * Get backup directory
     */
    public function getBackupDir(): string
    {
        return $this->backupDir;
    }

    /**
     * Set backup directory
     */
    public function setBackupDir(string $dir): void
    {
        $this->backupDir = $dir;
    }

    /**
     * Get log directory
     */
    public function getLogDir(): string
    {
        return $this->logDir;
    }

    /**
     * Set log directory
     */
    public function setLogDir(string $dir): void
    {
        $this->logDir = $dir;
    }

    /**
     * Get configuration file path that was loaded
     */
    public function getLoadedFromPath(): ?string
    {
        return $this->loadedFromPath;
    }

    /**
     * Get backup retention count
     */
    public function getBackupRetention(): int
    {
        return $this->backupRetention;
    }

    /**
     * Returns sanitized representation safe for logging or terminal display
     * Passwords and sensitive parameters are strictly masked!
     *
     * @return array<string, mixed>
     */
    public function getSanitizedInfo(): array
    {
        return [
            'driver'       => $this->driver,
            'db_host'      => $this->dbHost,
            'db_port'      => $this->dbPort,
            'db_user'      => $this->dbUser,
            'db_pass'      => !empty($this->dbPass) ? '******** (masked)' : '[empty]',
            'db_name'      => $this->dbName,
            'db_socket'    => $this->dbSocket ?? 'N/A',
            'backup_dir'   => $this->backupDir,
            'log_dir'      => $this->logDir,
            'loaded_from'  => $this->loadedFromPath ?? 'Direct Parameters / Environment',
        ];
    }
}

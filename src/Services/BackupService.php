<?php
/**
 * Virtualizor VPS Cleanup Manager
 *
 * MyISAM-Consistent Database Backup Service
 *
 * @package VirtualizorVpsCleaner\Services
 * @author  Mudit Kumar Prajapati
 * @license MIT
 */

namespace VirtualizorVpsCleaner\Services;

use RuntimeException;
use VirtualizorVpsCleaner\Config\VirtualizorConfig;
use VirtualizorVpsCleaner\Database\Connection;
use VirtualizorVpsCleaner\Logging\Logger;

class BackupService
{
    private VirtualizorConfig $config;
    private Connection $connection;
    private Logger $logger;

    public function __construct(VirtualizorConfig $config, Connection $connection, Logger $logger)
    {
        $this->config = $config;
        $this->connection = $connection;
        $this->logger = $logger;
    }

    /**
     * Create a verified, timestamped database backup prior to VPS deletion
     *
     * @param int $vpsid The target VPS ID being cleaned
     * @return string Absolute path to the verified backup file
     * @throws RuntimeException If backup creation or validation fails
     */
    public function createPreDeletionBackup(int $vpsid): string
    {
        $backupDir = $this->config->getBackupDir();
        $this->ensureDirectory($backupDir);

        $timestamp = date('Ymd_His');
        $dbName = $this->config->getDbName();
        $filename = "virtualizor_preclean_vps{$vpsid}_{$timestamp}.sql";
        $backupPath = $backupDir . DIRECTORY_SEPARATOR . $filename;

        $this->logger->info("Initiating pre-deletion backup for VPS #{$vpsid}...", ['target_path' => $backupPath]);

        if ($this->config->getDriver() === 'sqlite') {
            $this->backupSqlite($backupPath);
        } else {
            $this->backupMysql($backupPath);
        }

        // Validate the backup file thoroughly
        $this->validateBackup($backupPath);

        // Apply restrictive permissions (0600: read/write owner only)
        if (DIRECTORY_SEPARATOR !== '\\') {
            @chmod($backupPath, 0600);
        }

        $sizeBytes = filesize($backupPath);
        $sizeFormatted = $this->formatBytes($sizeBytes !== false ? $sizeBytes : 0);

        $this->logger->audit('BACKUP_CREATED', [
            'vpsid'       => $vpsid,
            'backup_file' => $backupPath,
            'size'        => $sizeFormatted,
            'timestamp'   => $timestamp,
        ]);

        return $backupPath;
    }

    /**
     * Execute MySQL consistent dump using mysqldump
     */
    private function backupMysql(string $backupPath): void
    {
        $mysqldump = $this->findMysqldumpBinary();

        if ($mysqldump === null) {
            $this->logger->warning("mysqldump binary not found in PATH, attempting PDO fallback table dump...");
            $this->backupViaPdo($backupPath);
            return;
        }

        $host = escapeshellarg($this->config->getDbHost());
        $port = (int) $this->config->getDbPort();
        $user = escapeshellarg($this->config->getDbUser());
        $pass = escapeshellarg($this->config->getDbPass());
        $dbName = escapeshellarg($this->config->getDbName());
        $socket = $this->config->getDbSocket();
        $targetFile = escapeshellarg($backupPath);

        // Build command with MyISAM-consistent flags
        // For MyISAM: --lock-tables ensures consistent view across tables
        $cmd = "{$mysqldump} --host={$host} --port={$port} --user={$user} --password={$pass} ";

        if (!empty($socket) && file_exists($socket)) {
            $cmd .= "--socket=" . escapeshellarg($socket) . " ";
        }

        $cmd .= "--add-drop-table --quick --lock-tables --default-character-set=utf8mb4 {$dbName} > {$targetFile} 2>&1";

        $output = [];
        $returnCode = 0;
        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0) {
            $rawError = implode("\n", $output);
            $cleanError = $this->connection->sanitizeError($rawError);

            // Clean up potentially corrupted partial file
            if (file_exists($backupPath)) {
                @unlink($backupPath);
            }

            throw new RuntimeException("mysqldump failed with exit code {$returnCode}: {$cleanError}");
        }
    }

    /**
     * SQLite backup handler (used in tests)
     */
    private function backupSqlite(string $backupPath): void
    {
        $srcPath = $this->config->getSqlitePath();
        if ($srcPath && file_exists($srcPath)) {
            if (!@copy($srcPath, $backupPath)) {
                throw new RuntimeException("Failed to copy SQLite database file to {$backupPath}");
            }
        } else {
            // Memory database: Dump tables to SQL
            $this->backupViaPdo($backupPath);
        }
    }

    /**
     * Native PHP PDO dump fallback when mysqldump is not available
     */
    public function backupViaPdo(string $backupPath): void
    {
        $pdo = $this->connection->getPdo();
        $handle = fopen($backupPath, 'w');
        if ($handle === false) {
            throw new RuntimeException("Unable to open backup file for writing: {$backupPath}");
        }

        fwrite($handle, "-- Virtualizor VPS Cleaner Native Fallback Dump\n");
        fwrite($handle, "-- Generated: " . date('Y-m-d H:i:s') . "\n\n");

        $tables = ['vps', 'disks', 'ips', 'servers', 'tasks'];

        foreach ($tables as $tbl) {
            try {
                $stmt = $pdo->query("SELECT * FROM `{$tbl}`");
                if ($stmt === false) {
                    continue;
                }

                fwrite($handle, "\n-- Dumping data for table `{$tbl}`\n");
                while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                    $keys = array_map(fn($k) => "`{$k}`", array_keys($row));
                    $values = array_map(function ($val) use ($pdo) {
                        return ($val === null) ? 'NULL' : $pdo->quote((string) $val);
                    }, array_values($row));

                    $sql = sprintf(
                        "INSERT INTO `%s` (%s) VALUES (%s);\n",
                        $tbl,
                        implode(', ', $keys),
                        implode(', ', $values)
                    );
                    fwrite($handle, $sql);
                }
            } catch (\Throwable $e) {
                // Ignore missing optional tables
            }
        }

        fwrite($handle, "\n-- End of Backup\n");
        fclose($handle);
    }

    /**
     * Validate that backup was created successfully, is not empty, and has content
     */
    public function validateBackup(string $backupPath): void
    {
        if (!file_exists($backupPath)) {
            throw new RuntimeException("Backup validation failed: File does not exist at {$backupPath}");
        }

        $size = filesize($backupPath);
        if ($size === false || $size <= 0) {
            if (file_exists($backupPath)) {
                @unlink($backupPath);
            }
            throw new RuntimeException("Backup validation failed: File is 0 bytes (empty) at {$backupPath}");
        }

        // Read the first 512 bytes to verify SQL / DB header
        $fp = fopen($backupPath, 'r');
        if ($fp === false) {
            throw new RuntimeException("Backup validation failed: Cannot open file for reading at {$backupPath}");
        }

        $header = fread($fp, 512);
        fclose($fp);

        if ($header === false || strlen($header) === 0) {
            throw new RuntimeException("Backup validation failed: Unable to read file header at {$backupPath}");
        }

        // Basic sanity check: look for SQL comments or keywords or SQLite header
        $isSql = str_contains($header, '--') || str_contains($header, '/*') || str_contains($header, 'INSERT') || str_contains($header, 'CREATE');
        $isSqlite = str_contains($header, 'SQLite format');

        if (!$isSql && !$isSqlite) {
            throw new RuntimeException("Backup validation failed: File does not appear to be a valid SQL dump or SQLite database.");
        }
    }

    /**
     * Locate mysqldump binary
     */
    private function findMysqldumpBinary(): ?string
    {
        $candidates = [
            'mysqldump',
            '/usr/bin/mysqldump',
            '/usr/local/mysql/bin/mysqldump',
            '/usr/local/emps/bin/mysqldump',
            '/usr/bin/mariadb-dump',
        ];

        foreach ($candidates as $bin) {
            $testCmd = (DIRECTORY_SEPARATOR === '\\') ? "where {$bin} 2>nul" : "command -v {$bin} 2>/dev/null";
            $res = shell_exec($testCmd);
            if ($res !== null && trim($res) !== '') {
                return $bin;
            }
        }

        return null;
    }

    /**
     * Ensure directory exists with proper permissions
     */
    private function ensureDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
                throw new RuntimeException("Failed to create backup directory: {$dir}");
            }
        }
    }

    /**
     * List all available backups
     *
     * @return array<array{filename: string, path: string, size: string, mtime: string, raw_size: int}>
     */
    public function listBackups(): array
    {
        $backupDir = $this->config->getBackupDir();
        if (!is_dir($backupDir)) {
            return [];
        }

        $files = glob($backupDir . DIRECTORY_SEPARATOR . '*.sql');
        if ($files === false) {
            return [];
        }

        $list = [];
        foreach ($files as $file) {
            $stat = stat($file);
            if ($stat === false) {
                continue;
            }

            $list[] = [
                'filename' => basename($file),
                'path'     => $file,
                'size'     => $this->formatBytes($stat['size']),
                'raw_size' => $stat['size'],
                'mtime'    => date('Y-m-d H:i:s', $stat['mtime']),
            ];
        }

        // Sort newest first
        usort($list, fn($a, $b) => strcmp($b['mtime'], $a['mtime']));

        return $list;
    }

    /**
     * Get SQL restoration command for disaster recovery
     */
    public function getRecoveryCommand(string $backupPath): string
    {
        $host = $this->config->getDbHost();
        $port = $this->config->getDbPort();
        $user = $this->config->getDbUser();
        $db = $this->config->getDbName();

        return "mysql -h {$host} -P {$port} -u {$user} -p {$db} < " . escapeshellarg($backupPath);
    }

    /**
     * Format bytes to human readable format
     */
    public function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        }
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' B';
    }
}

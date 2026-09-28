<?php
/**
 * Virtualizor VPS Cleanup Manager
 *
 * Base Test Case with Disposable In-Memory / File Database
 *
 * @package VirtualizorVpsCleaner\Tests
 * @author  Mudit Kumar Prajapati
 * @license MIT
 */

namespace VirtualizorVpsCleaner\Tests;

use PDO;
use VirtualizorVpsCleaner\Config\VirtualizorConfig;
use VirtualizorVpsCleaner\Database\Connection;
use VirtualizorVpsCleaner\Database\SchemaInspector;
use VirtualizorVpsCleaner\Logging\Logger;

class TestCase
{
    protected VirtualizorConfig $config;
    protected Connection $connection;
    protected SchemaInspector $inspector;
    protected Logger $logger;
    protected string $tempDir;
    protected string $tempDbFile;

    public function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vps_cleaner_test_' . uniqid();
        @mkdir($this->tempDir, 0750, true);
        @mkdir($this->tempDir . '/backups', 0750, true);
        @mkdir($this->tempDir . '/logs', 0750, true);

        $this->tempDbFile = $this->tempDir . DIRECTORY_SEPARATOR . 'test_virtualizor.sqlite';

        $this->config = new VirtualizorConfig([
            'driver'       => 'sqlite',
            'sqlite_path'  => $this->tempDbFile,
            'backup_dir'   => $this->tempDir . '/backups',
            'log_dir'      => $this->tempDir . '/logs',
            'name'         => 'virtualizor_test',
        ]);

        $this->connection = new Connection($this->config);
        $this->inspector = new SchemaInspector($this->connection);
        $this->logger = new Logger($this->config);

        $this->initializeTestDatabase();
    }

    public function tearDown(): void
    {
        $this->connection->unlockTables();

        // Recursively clean up temp files
        if (is_dir($this->tempDir)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tempDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $fileinfo) {
                $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
                @$todo($fileinfo->getRealPath());
            }
            @rmdir($this->tempDir);
        }
    }

    /**
     * Create Virtualizor schema and synthetic fixtures
     */
    protected function initializeTestDatabase(): void
    {
        $pdo = $this->connection->getPdo();

        // 1. Create servers table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `servers` (
                `serid` INTEGER PRIMARY KEY,
                `server_name` TEXT NOT NULL,
                `ip` TEXT NOT NULL,
                `status` INTEGER DEFAULT 1
            );
        ");

        // 2. Create vps table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `vps` (
                `vpsid` INTEGER PRIMARY KEY,
                `vps_name` TEXT NOT NULL,
                `uuid` TEXT NOT NULL UNIQUE,
                `serid` INTEGER NOT NULL,
                `uid` INTEGER NOT NULL,
                `hostname` TEXT,
                `os_name` TEXT,
                `ram` INTEGER DEFAULT 1024,
                `cores` INTEGER DEFAULT 1,
                `space` INTEGER DEFAULT 20,
                `status` TEXT DEFAULT '1'
            );
        ");

        // 3. Create disks table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `disks` (
                `did` INTEGER PRIMARY KEY AUTOINCREMENT,
                `vps_uuid` TEXT NOT NULL,
                `path` TEXT NOT NULL,
                `size` TEXT DEFAULT '20',
                `type` TEXT DEFAULT 'qcow2',
                `primary` INTEGER DEFAULT 1,
                `storage` TEXT DEFAULT 'default'
            );
        ");

        // 4. Create ips table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `ips` (
                `ipid` INTEGER PRIMARY KEY AUTOINCREMENT,
                `vpsid` INTEGER DEFAULT 0,
                `ip` TEXT NOT NULL,
                `ipv6` TEXT,
                `mac` TEXT,
                `netmask` TEXT,
                `gateway` TEXT,
                `serid` INTEGER DEFAULT 0,
                `locked` INTEGER DEFAULT 1
            );
        ");

        // 5. Create tasks table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `tasks` (
                `taskid` INTEGER PRIMARY KEY AUTOINCREMENT,
                `vpsid` INTEGER NOT NULL,
                `action` TEXT NOT NULL,
                `status` INTEGER DEFAULT 0,
                `time` INTEGER NOT NULL,
                `data` TEXT
            );
        ");

        $this->seedSyntheticData($pdo);
    }

    /**
     * Seed synthetic data mimicking real Virtualizor records
     */
    protected function seedSyntheticData(PDO $pdo): void
    {
        // Servers
        $pdo->exec("
            INSERT INTO `servers` (`serid`, `server_name`, `ip`, `status`) VALUES
            (0, 'Master Server', '10.0.0.1', 1),
            (10, 'Retired-Dedicated-Node-A', '198.51.100.10', 0),
            (20, 'Active-Customer-Node-B', '198.51.100.20', 1);
        ");

        // VPS instances
        // VPS 101: Orphaned on Retired Server 10 (Target for deletion)
        // VPS 102: Active on Active Server 20 (MUST REMAIN INTACT)
        // VPS 103: Orphaned with no disks or IPs (Edge case)
        // VPS 104: VPS with pending tasks (Must be blocked from deletion)
        $pdo->exec("
            INSERT INTO `vps` (`vpsid`, `vps_name`, `uuid`, `serid`, `uid`, `hostname`, `os_name`, `ram`, `cores`, `space`, `status`) VALUES
            (101, 'v1001', 'uuid-orphaned-101', 10, 5, 'old-vm1.example.com', 'Ubuntu 20.04', 2048, 2, 40, '0'),
            (102, 'v1002', 'uuid-active-102', 20, 8, 'live-client.example.com', 'Debian 11', 4096, 4, 80, '1'),
            (103, 'v1003', 'uuid-bare-103', 10, 2, 'bare-vm.example.com', 'CentOS 7', 1024, 1, 20, '0'),
            (104, 'v1004', 'uuid-task-104', 10, 3, 'task-vm.example.com', 'Ubuntu 22.04', 2048, 2, 50, '0');
        ");

        // Disks
        $pdo->exec("
            INSERT INTO `disks` (`did`, `vps_uuid`, `path`, `size`, `type`, `primary`, `storage`) VALUES
            (1, 'uuid-orphaned-101', '/var/virtualizor/kvm/v1001.img', '40', 'qcow2', 1, 'default'),
            (2, 'uuid-orphaned-101', '/var/virtualizor/kvm/v1001_data.img', '50', 'raw', 0, 'secondary'),
            (3, 'uuid-active-102', '/var/virtualizor/kvm/v1002.img', '80', 'qcow2', 1, 'default'),
            (4, 'uuid-task-104', '/var/virtualizor/kvm/v1004.img', '50', 'qcow2', 1, 'default');
        ");

        // IPs
        $pdo->exec("
            INSERT INTO `ips` (`ipid`, `vpsid`, `ip`, `ipv6`, `mac`, `serid`, `locked`) VALUES
            (10, 101, '198.51.100.101', '2001:db8::101', '52:54:00:11:22:33', 10, 1),
            (20, 102, '198.51.100.102', '2001:db8::102', '52:54:00:44:55:66', 20, 1),
            (30, 0,   '198.51.100.200', NULL, NULL, 10, 0),
            (40, 104, '198.51.100.104', NULL, NULL, 10, 1);
        ");

        // Tasks (VPS 104 has an active running task)
        $timeNow = time();
        $pdo->exec("
            INSERT INTO `tasks` (`taskid`, `vpsid`, `action`, `status`, `time`) VALUES
            (1, 101, 'stopvps', 1, {$timeNow} - 86400),
            (2, 104, 'rebuildvps', 0, {$timeNow});
        ");
    }

    // Basic assertion helpers for standalone runner
    protected function assertTrue(bool $condition, string $message = ''): void
    {
        if (!$condition) {
            throw new \AssertionError($message ?: "Failed asserting that condition is true.");
        }
    }

    protected function assertFalse(bool $condition, string $message = ''): void
    {
        if ($condition) {
            throw new \AssertionError($message ?: "Failed asserting that condition is false.");
        }
    }

    protected function assertEquals($expected, $actual, string $message = ''): void
    {
        if ($expected != $actual) {
            $msg = $message ?: sprintf("Failed asserting that %s matches expected %s.", var_export($actual, true), var_export($expected, true));
            throw new \AssertionError($msg);
        }
    }

    protected function assertSame($expected, $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            $msg = $message ?: sprintf("Failed asserting that %s is identical to %s.", var_export($actual, true), var_export($expected, true));
            throw new \AssertionError($msg);
        }
    }

    protected function assertCount(int $expectedCount, $countable, string $message = ''): void
    {
        $actual = count($countable);
        if ($expectedCount !== $actual) {
            throw new \AssertionError($message ?: "Failed asserting count. Expected: {$expectedCount}, got: {$actual}");
        }
    }

    protected function assertStringContains(string $needle, string $haystack, string $message = ''): void
    {
        if (!str_contains($haystack, $needle)) {
            throw new \AssertionError($message ?: "Failed asserting that string '{$haystack}' contains '{$needle}'.");
        }
    }

    protected function assertNotNull($actual, string $message = ''): void
    {
        if ($actual === null) {
            throw new \AssertionError($message ?: "Failed asserting that value is not null.");
        }
    }

    protected function assertNull($actual, string $message = ''): void
    {
        if ($actual !== null) {
            throw new \AssertionError($message ?: "Failed asserting that value is null.");
        }
    }
}

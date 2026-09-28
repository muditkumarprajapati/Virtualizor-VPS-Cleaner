<?php
/**
 * Virtualizor VPS Cleanup Manager
 *
 * Database Connection and Table Lock Test Suite
 *
 * @package VirtualizorVpsCleaner\Tests
 * @author  Mudit Kumar Prajapati
 * @license MIT
 */

namespace VirtualizorVpsCleaner\Tests;

use PDO;
use RuntimeException;
use VirtualizorVpsCleaner\Config\VirtualizorConfig;
use VirtualizorVpsCleaner\Database\Connection;

class DatabaseTest extends TestCase
{
    public function testConnectionPing(): void
    {
        $this->assertTrue($this->connection->ping());
    }

    public function testPdoInstance(): void
    {
        $pdo = $this->connection->getPdo();
        $this->assertInstanceOf(PDO::class, $pdo);
        $this->assertEquals(PDO::ERRMODE_EXCEPTION, $pdo->getAttribute(PDO::ATTR_ERRMODE));
    }

    public function testCredentialMaskingInErrorMessages(): void
    {
        $secretPass = "SuperConfidentialPass99!";
        $badConfig = new VirtualizorConfig([
            'driver' => 'mysql',
            'host'   => '127.0.0.1',
            'port'   => 59999, // Unreachable port
            'user'   => 'root',
            'pass'   => $secretPass,
            'name'   => 'virtualizor',
        ]);

        $badConn = new Connection($badConfig);

        $threw = false;
        try {
            $badConn->getPdo();
        } catch (RuntimeException $e) {
            $threw = true;
            $errorMsg = $e->getMessage();
            $this->assertFalse(str_contains($errorMsg, $secretPass), "Raw password must NEVER appear in error message!");
        }

        $this->assertTrue($threw, "Expected RuntimeException on failed connection.");
    }

    public function testTableLockAndUnlock(): void
    {
        $this->assertFalse($this->connection->areTablesLocked());

        $this->connection->lockTables([
            'vps'   => 'WRITE',
            'disks' => 'WRITE',
            'ips'   => 'WRITE',
        ]);

        $this->assertTrue($this->connection->areTablesLocked());
        $this->assertEquals(['vps', 'disks', 'ips'], $this->connection->getLockedTables());

        $this->connection->unlockTables();
        $this->assertFalse($this->connection->areTablesLocked());
    }

    private function assertInstanceOf(string $expected, $actual): void
    {
        if (!($actual instanceof $expected)) {
            throw new \AssertionError("Failed asserting that object is instance of {$expected}.");
        }
    }
}

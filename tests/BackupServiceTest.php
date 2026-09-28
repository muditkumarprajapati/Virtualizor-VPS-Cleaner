<?php
/**
 * Virtualizor VPS Cleanup Manager
 *
 * Backup Service Test Suite
 *
 * @package VirtualizorVpsCleaner\Tests
 * @author  Mudit Kumar Prajapati
 * @license MIT
 */

namespace VirtualizorVpsCleaner\Tests;

use RuntimeException;
use VirtualizorVpsCleaner\Services\BackupService;

class BackupServiceTest extends TestCase
{
    private BackupService $backupService;

    public function setUp(): void
    {
        parent::setUp();
        $this->backupService = new BackupService($this->config, $this->connection, $this->logger);
    }

    public function testCreateAndValidateBackup(): void
    {
        $backupPath = $this->backupService->createPreDeletionBackup(101);

        $this->assertTrue(file_exists($backupPath));
        $this->assertTrue(filesize($backupPath) > 0);

        // Explicit validation check
        $this->backupService->validateBackup($backupPath);

        // List backups
        $list = $this->backupService->listBackups();
        $this->assertCount(1, $list);
        $this->assertEquals(basename($backupPath), $list[0]['filename']);
    }

    public function testValidationFailsOnEmptyFile(): void
    {
        $emptyFile = $this->tempDir . '/backups/empty_corrupted.sql';
        file_put_contents($emptyFile, '');

        $threw = false;
        try {
            $this->backupService->validateBackup($emptyFile);
        } catch (RuntimeException $e) {
            $threw = true;
            $this->assertStringContains("0 bytes", $e->getMessage());
        }

        $this->assertTrue($threw, "Expected empty backup validation to throw RuntimeException.");
    }

    public function testRecoveryCommandFormat(): void
    {
        $dummyPath = '/var/backups/virtualizor_test.sql';
        $cmd = $this->backupService->getRecoveryCommand($dummyPath);

        $this->assertStringContains("mysql", $cmd);
        $this->assertStringContains("virtualizor_test", $cmd);
    }

    public function testFormatBytes(): void
    {
        $this->assertEquals("500 B", $this->backupService->formatBytes(500));
        $this->assertEquals("1.50 KB", $this->backupService->formatBytes(1536));
        $this->assertEquals("2.00 MB", $this->backupService->formatBytes(2097152));
    }
}

<?php
/**
 * Virtualizor VPS Cleanup Manager
 *
 * Cleanup Service and Safety Guard Test Suite
 *
 * @package VirtualizorVpsCleaner\Tests
 * @author  Mudit Kumar Prajapati
 * @license MIT
 */

namespace VirtualizorVpsCleaner\Tests;

use VirtualizorVpsCleaner\Services\VpsService;
use VirtualizorVpsCleaner\Services\BackupService;
use VirtualizorVpsCleaner\Services\CleanupService;

class CleanupServiceTest extends TestCase
{
    private VpsService $vpsService;
    private BackupService $backupService;
    private CleanupService $cleanupService;

    public function setUp(): void
    {
        parent::setUp();
        $this->vpsService = new VpsService($this->connection, $this->inspector);
        $this->backupService = new BackupService($this->config, $this->connection, $this->logger);
        $this->cleanupService = new CleanupService($this->connection, $this->inspector, $this->backupService, $this->logger);
    }

    public function testSafetyValidationBlocksVpsWithPendingTasks(): void
    {
        $vpsWithTask = $this->vpsService->findById(104);
        $this->assertNotNull($vpsWithTask);

        $validation = $this->cleanupService->validateSafety($vpsWithTask);
        $this->assertFalse($validation['safe'], "VPS with active tasks must be blocked from deletion!");
        $this->assertCount(1, $validation['blockers']);
        $this->assertStringContains("Active or pending tasks detected", $validation['blockers'][0]);
    }

    public function testSafetyValidationWarnsOnOnlineServer(): void
    {
        $activeVps = $this->vpsService->findById(102);
        $this->assertNotNull($activeVps);

        $validation = $this->cleanupService->validateSafety($activeVps);
        $this->assertTrue(count($validation['warnings']) > 0);
        $this->assertStringContains("reuse the original IP or Virtualizor server ID", implode(' ', $validation['warnings']));
    }

    public function testDryRunDoesNotModifyDatabase(): void
    {
        $vps = $this->vpsService->findById(101);
        $this->assertNotNull($vps);

        $dryRun = $this->cleanupService->generateDryRunReport($vps);

        $this->assertEquals(101, $dryRun['vpsToDelete']['vpsid']);
        $this->assertCount(2, $dryRun['disksToDelete']);
        $this->assertCount(1, $dryRun['ipsToModify']);
        $this->assertCount(6, $dryRun['sqlStatements']);
        $this->assertTrue(count($dryRun['untouched']) > 0);

        // Verify database is completely unchanged
        $recheck = $this->vpsService->findById(101);
        $this->assertNotNull($recheck);
        $this->assertCount(2, $recheck->getDisks());
        $this->assertCount(1, $recheck->getIps());
    }

    public function testExecuteGuardedCleanupWorkflow(): void
    {
        // Create a dummy physical file to prove it is NEVER deleted or touched
        $dummyDiskFile = $this->tempDir . '/dummy_v1001.img';
        file_put_contents($dummyDiskFile, "IMPORTANT CUSTOMER DATA NEVER TOUCH");

        $vps = $this->vpsService->findById(101);
        $this->assertNotNull($vps);

        // Pre-create backup
        $backupPath = $this->backupService->createPreDeletionBackup(101);
        $this->assertTrue(file_exists($backupPath));

        // Execute cleanup
        $result = $this->cleanupService->executeCleanup($vps, $backupPath);

        $this->assertTrue($result['success']);
        $this->assertNull($result['error']);
        $this->assertEquals(2, $result['unlinkedDisks']);
        $this->assertEquals(1, $result['unlinkedIps']);
        $this->assertTrue($result['verifiedDeleted']);

        // Check target VPS is gone
        $deletedVps = $this->vpsService->findById(101);
        $this->assertNull($deletedVps);

        // Check disk metadata for that UUID is gone
        $pdo = $this->connection->getPdo();
        $diskCount = (int) $pdo->query("SELECT COUNT(*) FROM `disks` WHERE `vps_uuid` = 'uuid-orphaned-101'")->fetchColumn();
        $this->assertEquals(0, $diskCount);

        // Check IP was unlinked (vpsid = 0) but PRESERVED and KEPT RESERVED (locked = 1)
        $ipRow = $pdo->query("SELECT * FROM `ips` WHERE `ip` = '198.51.100.101'")->fetch();
        $this->assertNotNull($ipRow);
        $this->assertEquals(0, (int) $ipRow['vpsid']);
        $this->assertEquals(1, (int) $ipRow['locked']);

        // Check unrelated VPS (102) remains untouched!
        $activeVps = $this->vpsService->findById(102);
        $this->assertNotNull($activeVps);
        $this->assertCount(1, $activeVps->getDisks());
        $this->assertCount(1, $activeVps->getIps());

        // Check physical dummy disk file is 100% UNTOUCHED
        $this->assertTrue(file_exists($dummyDiskFile));
        $this->assertEquals("IMPORTANT CUSTOMER DATA NEVER TOUCH", file_get_contents($dummyDiskFile));

        // Check tables are unlocked
        $this->assertFalse($this->connection->areTablesLocked());
    }

    public function testCleanupAbortsIfRecordChangedBetweenInspectionAndDeletion(): void
    {
        $vps = $this->vpsService->findById(101);
        $this->assertNotNull($vps);

        $backupPath = $this->backupService->createPreDeletionBackup(101);

        // Simulate external change: UUID modified in DB before lock
        $pdo = $this->connection->getPdo();
        $pdo->exec("UPDATE `vps` SET `uuid` = 'uuid-changed-externally' WHERE `vpsid` = 101");

        $result = $this->cleanupService->executeCleanup($vps, $backupPath);

        $this->assertFalse($result['success']);
        $this->assertStringContains("UUID mismatch", $result['error']);

        // Verify tables are safely unlocked despite error
        $this->assertFalse($this->connection->areTablesLocked());
    }

    public function testSimulatePartialFailureDuringDeletion(): void
    {
        $vps = $this->vpsService->findById(101);
        $this->assertNotNull($vps);

        $backupPath = $this->backupService->createPreDeletionBackup(101);

        // Simulate a corrupted table or trigger failure by creating a trigger that fails on DELETE
        $pdo = $this->connection->getPdo();
        $pdo->exec("
            CREATE TRIGGER fail_on_vps_delete
            BEFORE DELETE ON `vps`
            BEGIN
                SELECT RAISE(FAIL, 'Simulated MyISAM table write failure / hardware error');
            END;
        ");

        $result = $this->cleanupService->executeCleanup($vps, $backupPath);

        // Must report failure
        $this->assertFalse($result['success']);
        $this->assertNotNull($result['error']);
        $this->assertStringContains('Simulated MyISAM table write failure', $result['error']);

        // CRITICAL: Tables MUST be unlocked even when an unhandled exception or trigger failure occurs
        $this->assertFalse($this->connection->areTablesLocked(), "Tables must be unlocked in finally block!");

        // Backup file must remain intact for disaster recovery
        $this->assertTrue(file_exists($backupPath), "Pre-deletion backup must be preserved for recovery!");
        $this->assertTrue(filesize($backupPath) > 0);
    }
}

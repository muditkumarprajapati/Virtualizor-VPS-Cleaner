<?php
/**
 * Virtualizor VPS Cleanup Manager
 *
 * 7-Step Guarded VPS Cleanup and Safety Validation Engine
 *
 * @package VirtualizorVpsCleaner\Services
 * @author  Mudit Kumar Prajapati
 * @license MIT
 */

namespace VirtualizorVpsCleaner\Services;

use PDO;
use RuntimeException;
use Throwable;
use VirtualizorVpsCleaner\Database\Connection;
use VirtualizorVpsCleaner\Database\SchemaInspector;
use VirtualizorVpsCleaner\Models\Vps;
use VirtualizorVpsCleaner\Logging\Logger;

class CleanupService
{
    private Connection $connection;
    private SchemaInspector $inspector;
    private BackupService $backupService;
    private Logger $logger;

    public function __construct(
        Connection $connection,
        SchemaInspector $inspector,
        BackupService $backupService,
        Logger $logger
    ) {
        $this->connection = $connection;
        $this->inspector = $inspector;
        $this->backupService = $backupService;
        $this->logger = $logger;
    }

    /**
     * STEP 2: Safety validation
     * Checks if the VPS can be safely targeted for deletion
     *
     * @param Vps $vps
     * @return array{safe: bool, blockers: array<string>, warnings: array<string>}
     */
    public function validateSafety(Vps $vps): array
    {
        $blockers = [];
        $warnings = [];

        // 1. Check for pending tasks
        if ($vps->hasPendingTasks()) {
            $blockers[] = "Active or pending tasks detected for VPS #{$vps->getVpsid()}! " .
                          "Deleting a VPS during an active Virtualizor task will corrupt queue state.";
        }

        // 2. Server check & recycled IP/ID warning
        $server = $vps->getServer();
        $serid = $vps->getSerid();

        if ($serid === 0) {
            $warnings[] = "CRITICAL: VPS is associated with the Master Server (serid 0). " .
                          "Ensure this is truly an orphaned record before proceeding.";
        } elseif ($server !== null && $server->isOnline()) {
            $warnings[] = "WARNING: Associated server '{$server->getServerName()}' (ID {$serid}) is marked ONLINE. " .
                          "A newly reinstalled server might reuse the original IP or Virtualizor server ID. " .
                          "Never assume matching details proves a VPS is obsolete!";
        }

        // 3. Schema validation check
        $schemaCheck = $this->inspector->validateSchema();
        if (!$schemaCheck['valid']) {
            foreach ($schemaCheck['errors'] as $err) {
                $blockers[] = "Database schema mismatch: {$err}";
            }
        }

        // 4. MyISAM Alert
        $myIsam = $this->inspector->getMyIsamTables();
        if (!empty($myIsam)) {
            $warnings[] = "Tables (" . implode(', ', $myIsam) . ") use the MyISAM storage engine. " .
                          "Transactional rollback (ROLLBACK) is NOT supported! Full backup and table locks will be applied.";
        }

        return [
            'safe'     => empty($blockers),
            'blockers' => $blockers,
            'warnings' => $warnings,
        ];
    }

    /**
     * STEP 3: Dry run report
     * Calculates exact modifications and what will remain untouched
     *
     * @param Vps $vps
     * @return array{
     *   vpsToDelete: array{vpsid: int, vps_name: string, uuid: string},
     *   disksToDelete: array<array<string, mixed>>,
     *   ipsToModify: array<array<string, mixed>>,
     *   sqlStatements: array<string>,
     *   untouched: array<string>
     * }
     */
    public function generateDryRunReport(Vps $vps): array
    {
        $vpsid = $vps->getVpsid();
        $uuid = $vps->getUuid();

        $disksData = [];
        foreach ($vps->getDisks() as $disk) {
            $disksData[] = [
                'did'     => $disk->getDid(),
                'path'    => $disk->getPath(),
                'size'    => $disk->getSize(),
                'type'    => $disk->getType(),
                'storage' => $disk->getStorage() ?? 'Default',
            ];
        }

        $ipsData = [];
        foreach ($vps->getIps() as $ip) {
            $ipsData[] = [
                'ipid'         => $ip->getIpid(),
                'ip'           => $ip->getIp(),
                'ipv6'         => $ip->getIpv6() ?? 'N/A',
                'current_vps'  => $vpsid,
                'action'       => 'Disassociate (set vpsid = 0) and preserve locked = 1',
            ];
        }

        $sqlStatements = [
            "-- 1. Disassociate assigned IP addresses (reserve without freeing)",
            "UPDATE `ips` SET `vpsid` = 0 WHERE `vpsid` = {$vpsid};",
            "-- 2. Remove disk database metadata for this VPS UUID",
            "DELETE FROM `disks` WHERE `vps_uuid` = '{$uuid}';",
            "-- 3. Remove primary VPS database record",
            "DELETE FROM `vps` WHERE `vpsid` = {$vpsid};",
        ];

        $untouched = [
            "Physical disk files (" . (count($disksData) > 0 ? implode(', ', array_column($disksData, 'path')) : 'None') . ") - REMAIN INTACT",
            "Physical hardware nodes, slave servers, and hypervisors - REMAIN UNTOUCHED",
            "Server entry in 'servers' table (serid {$vps->getSerid()}) - REMAINS UNTOUCHED",
            "Historical activity logs ('actid', 'logs') - PRESERVED FOR AUDITING",
            "IP records in 'ips' table - RETAINED IN POOL (kept reserved, not deleted)",
            "Cloud mounts, Google Drive, or rclone directories - NEVER ACCESSED OR DELETED",
        ];

        return [
            'vpsToDelete'   => [
                'vpsid'    => $vpsid,
                'vps_name' => $vps->getVpsName(),
                'uuid'     => $uuid,
            ],
            'disksToDelete' => $disksData,
            'ipsToModify'   => $ipsData,
            'sqlStatements' => $sqlStatements,
            'untouched'     => $untouched,
        ];
    }

    /**
     * STEP 6: Guarded cleanup execution
     * Locks tables, revalidates state, executes targeted queries, and unlocks tables in finally block.
     *
     * @param Vps $vps
     * @param string $backupPath
     * @return array{
     *   success: bool,
     *   error: ?string,
     *   unlinkedDisks: int,
     *   unlinkedIps: int,
     *   verifiedDeleted: bool
     * }
     */
    public function executeCleanup(Vps $vps, string $backupPath): array
    {
        $vpsid = $vps->getVpsid();
        $uuid = $vps->getUuid();
        $pdo = $this->connection->getPdo();

        $this->logger->info("Beginning guarded cleanup for VPS #{$vpsid} ({$vps->getVpsName()})...");

        // Prepare table locks
        $tablesToLock = [
            'vps'   => 'WRITE',
            'disks' => 'WRITE',
            'ips'   => 'WRITE',
        ];
        if ($this->inspector->hasTable('servers')) {
            $tablesToLock['servers'] = 'READ';
        }
        if ($this->inspector->hasTable('tasks')) {
            $tablesToLock['tasks'] = 'READ';
        }

        $unlinkedDisks = 0;
        $unlinkedIps = 0;
        $verifiedDeleted = false;

        try {
            // 1. Lock database tables for MyISAM safety
            $this->connection->lockTables($tablesToLock);

            // 2. Revalidate record immediately after lock to prevent race conditions
            $checkStmt = $pdo->prepare("SELECT `vpsid`, `vps_name`, `uuid` FROM `vps` WHERE `vpsid` = :vpsid");
            $checkStmt->execute([':vpsid' => $vpsid]);
            $currentRecord = $checkStmt->fetch();

            if (!$currentRecord) {
                throw new RuntimeException("Revalidation failed: VPS #{$vpsid} was already removed or does not exist!");
            }
            if ($currentRecord['uuid'] !== $uuid) {
                throw new RuntimeException("Revalidation failed: VPS UUID mismatch! Expected {$uuid}, found {$currentRecord['uuid']}");
            }

            // Check tasks again under lock
            if ($this->inspector->hasTable('tasks') && $this->inspector->hasColumn('tasks', 'vpsid') && $this->inspector->hasColumn('tasks', 'status')) {
                $taskStmt = $pdo->prepare("SELECT COUNT(*) FROM `tasks` WHERE `vpsid` = :vpsid AND `status` = 0");
                $taskStmt->execute([':vpsid' => $vpsid]);
                if ((int) $taskStmt->fetchColumn() > 0) {
                    throw new RuntimeException("Revalidation failed: A pending task was initiated for VPS #{$vpsid} during operation!");
                }
            }

            // 3. Step A: Disassociate IP records (keep reserved)
            if ($this->inspector->hasTable('ips')) {
                $ipStmt = $pdo->prepare("UPDATE `ips` SET `vpsid` = 0 WHERE `vpsid` = :vpsid");
                $ipStmt->execute([':vpsid' => $vpsid]);
                $unlinkedIps = $ipStmt->rowCount();
            }

            // 4. Step B: Delete disk database metadata
            if ($this->inspector->hasTable('disks') && !empty($uuid)) {
                $diskStmt = $pdo->prepare("DELETE FROM `disks` WHERE `vps_uuid` = :uuid");
                $diskStmt->execute([':uuid' => $uuid]);
                $unlinkedDisks = $diskStmt->rowCount();
            }

            // 5. Step C: Delete primary VPS record
            $vpsStmt = $pdo->prepare("DELETE FROM `vps` WHERE `vpsid` = :vpsid");
            $vpsStmt->execute([':vpsid' => $vpsid]);
            $deletedVpsRows = $vpsStmt->rowCount();

            if ($deletedVpsRows !== 1) {
                throw new RuntimeException("Expected 1 row deleted from `vps`, but {$deletedVpsRows} rows were affected.");
            }

            // STEP 7 Verification: confirm row is gone
            $verifyStmt = $pdo->prepare("SELECT COUNT(*) FROM `vps` WHERE `vpsid` = :vpsid");
            $verifyStmt->execute([':vpsid' => $vpsid]);
            $verifiedDeleted = ((int) $verifyStmt->fetchColumn() === 0);

            if (!$verifiedDeleted) {
                throw new RuntimeException("Verification failed: VPS #{$vpsid} still exists in the database after deletion query!");
            }

            $this->logger->audit('VPS_DELETED', [
                'vpsid'            => $vpsid,
                'vps_name'         => $vps->getVpsName(),
                'uuid'             => $uuid,
                'serid'            => $vps->getSerid(),
                'unlinked_ips'     => $unlinkedIps,
                'unlinked_disks'   => $unlinkedDisks,
                'verified_deleted' => true,
                'backup_file'      => $backupPath,
            ]);

            return [
                'success'         => true,
                'error'           => null,
                'unlinkedDisks'   => $unlinkedDisks,
                'unlinkedIps'     => $unlinkedIps,
                'verifiedDeleted' => true,
            ];

        } catch (Throwable $e) {
            $errorMsg = $this->connection->sanitizeError($e->getMessage());
            $this->logger->error("Guarded cleanup failure for VPS #{$vpsid}: {$errorMsg}", [
                'vpsid'       => $vpsid,
                'backup_file' => $backupPath,
            ]);

            return [
                'success'         => false,
                'error'           => $errorMsg,
                'unlinkedDisks'   => $unlinkedDisks,
                'unlinkedIps'     => $unlinkedIps,
                'verifiedDeleted' => false,
            ];

        } finally {
            // ALWAYS release table locks regardless of errors
            $this->connection->unlockTables();
        }
    }
}

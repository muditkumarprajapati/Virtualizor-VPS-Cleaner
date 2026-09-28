<?php
/**
 * Virtualizor VPS Cleanup Manager
 *
 * Command Line Application Orchestrator and Interactive Menu Loop
 *
 * @package VirtualizorVpsCleaner\Cli
 * @author  Mudit Kumar Prajapati
 * @license MIT
 */

namespace VirtualizorVpsCleaner\Cli;

use Throwable;
use VirtualizorVpsCleaner\Config\VirtualizorConfig;
use VirtualizorVpsCleaner\Database\Connection;
use VirtualizorVpsCleaner\Database\SchemaInspector;
use VirtualizorVpsCleaner\Services\VpsService;
use VirtualizorVpsCleaner\Services\BackupService;
use VirtualizorVpsCleaner\Services\CleanupService;
use VirtualizorVpsCleaner\Services\VirtualizorApiService;
use VirtualizorVpsCleaner\Logging\Logger;
use VirtualizorVpsCleaner\Terminal\Ansi;
use VirtualizorVpsCleaner\Terminal\Table;
use VirtualizorVpsCleaner\Terminal\Prompt;

class App
{
    public const VERSION = '1.0.0';

    private VirtualizorConfig $config;
    private Connection $connection;
    private SchemaInspector $inspector;
    private VpsService $vpsService;
    private BackupService $backupService;
    private CleanupService $cleanupService;
    private VirtualizorApiService $apiService;
    private Logger $logger;

    /**
     * @param VirtualizorConfig $config
     */
    public function __construct(VirtualizorConfig $config)
    {
        $this->config = $config;
        $this->logger = new Logger($config);
        $this->connection = new Connection($config);
        $this->inspector = new SchemaInspector($this->connection);
        $this->backupService = new BackupService($config, $this->connection, $this->logger);
        $this->cleanupService = new CleanupService($this->connection, $this->inspector, $this->backupService, $this->logger);
        $this->apiService = new VirtualizorApiService($this->logger);

        $this->vpsService = new VpsService($this->connection, $this->inspector);
        $this->registerSignalHandlers();
    }

    /**
     * Register POSIX signal handlers if available (Ctrl+C handling)
     */
    private function registerSignalHandlers(): void
    {
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, function () {
                echo "\n\n" . Ansi::yellow("Operation cancelled by user (SIGINT). Ensuring locks released...") . "\n";
                $this->connection->unlockTables();
                exit(130);
            });
            pcntl_signal(SIGTERM, function () {
                $this->connection->unlockTables();
                exit(143);
            });
        }
    }

    /**
     * Main entry point
     *
     * @param array<string> $argv
     * @return int Exit code
     */
    public function run(array $argv): int
    {
        $args = $this->parseArguments($argv);

        if (isset($args['no-ansi'])) {
            Ansi::setEnabled(false);
        }

        if (isset($args['help']) || isset($args['h'])) {
            $this->printHelp();
            return 0;
        }

        if (isset($args['version']) || isset($args['v'])) {
            echo "Virtualizor VPS Cleanup Manager version " . self::VERSION . " (PHP " . PHP_VERSION . ")\n";
            return 0;
        }

        // Validate database connection early
        try {
            $this->connection->getPdo();
        } catch (Throwable $e) {
            Prompt::alert('danger', 'Database Connection Error', [
                "Could not connect to the database specified in Virtualizor configuration.",
                $e->getMessage(),
                "",
                "Configuration checked: " . ($this->config->getLoadedFromPath() ?? 'Environment / Defaults'),
                "Run with --config /path/to/universal.php or verify DB credentials.",
            ]);
            return 1;
        }

        // CLI flags execution
        if (isset($args['list'])) {
            $page = isset($args['page']) ? (int) $args['page'] : 1;
            $limit = isset($args['limit']) ? (int) $args['limit'] : 20;
            $server = isset($args['server']) ? (int) $args['server'] : null;
            return $this->cmdList($page, $limit, null, $server);
        }

        if (isset($args['search'])) {
            $query = is_string($args['search']) ? $args['search'] : '';
            return $this->cmdList(1, 50, $query);
        }

        if (isset($args['inspect'])) {
            $vpsid = (int) $args['inspect'];
            return $this->cmdInspect($vpsid);
        }

        if (isset($args['dry-run'])) {
            if (isset($args['delete'])) {
                $vpsid = (int) $args['delete'];
                return $this->cmdDryRun($vpsid);
            }
            echo Ansi::yellow("The --dry-run option must be accompanied by --delete <vpsid>\n");
            return 1;
        }

        if (isset($args['delete'])) {
            $vpsid = (int) $args['delete'];
            return $this->cmdDeleteWorkflow($vpsid);
        }

        if (isset($args['backups'])) {
            return $this->cmdBackups();
        }

        if (isset($args['history'])) {
            return $this->cmdHistory();
        }

        if (isset($args['schema'])) {
            return $this->cmdSchema();
        }

        // No command-line flag provided: enter Interactive Terminal Mode
        return $this->runInteractiveLoop();
    }

    /**
     * Interactive terminal main menu loop
     */
    private function runInteractiveLoop(): int
    {
        while (true) {
            Prompt::banner();
            $this->displayQuickStatus();

            $options = [
                '1' => 'List all VPS instances (paginated table)',
                '2' => 'Search VPS by ID, name or IP',
                '3' => 'View detailed VPS information',
                '4' => Ansi::bold(Ansi::brightRed('Remove a selected VPS (7-Step Guarded Cleanup)')),
                '5' => 'View database backup history',
                '6' => 'View cleanup audit history',
                '7' => 'Refresh database and schema information',
                '0' => 'Exit',
            ];

            $choice = Prompt::menu('MAIN MENU', $options, '1');

            switch ($choice) {
                case '1':
                    $this->interactiveList();
                    break;
                case '2':
                    $query = Prompt::ask('Enter search term (VPS ID, name, hostname, or IP)');
                    if ($query !== '') {
                        $this->interactiveList(1, 15, $query);
                    }
                    break;
                case '3':
                    $idInput = Prompt::ask('Enter VPS ID to inspect');
                    if (is_numeric($idInput)) {
                        $this->cmdInspect((int) $idInput);
                        Prompt::pause();
                    }
                    break;
                case '4':
                    $idInput = Prompt::ask('Enter VPS ID to remove');
                    if (is_numeric($idInput)) {
                        $this->cmdDeleteWorkflow((int) $idInput);
                        Prompt::pause();
                    }
                    break;
                case '5':
                    $this->cmdBackups();
                    Prompt::pause();
                    break;
                case '6':
                    $this->cmdHistory();
                    Prompt::pause();
                    break;
                case '7':
                    $this->cmdSchema();
                    Prompt::pause();
                    break;
                case '0':
                case 'q':
                case 'exit':
                    echo "\n" . Ansi::green("Goodbye! Thank you for using Virtualizor VPS Cleanup Manager.") . "\n\n";
                    return 0;
                default:
                    echo Ansi::yellow("Invalid selection. Please choose an option from the menu.\n");
            }
        }
    }

    /**
     * Display top bar system status
     */
    private function displayQuickStatus(): void
    {
        $summary = $this->vpsService->getSystemSummary();
        $myIsam = $this->inspector->getMyIsamTables();

        $engineBadge = empty($myIsam)
            ? Ansi::green("[InnoDB/Standard]")
            : Ansi::yellow("[MyISAM Detected - Strict Locking Enabled]");

        echo Ansi::gray(" Database: ") . Ansi::cyan($this->config->getDbName()) . "  " . $engineBadge . "\n";
        echo Ansi::gray(" Registered VPS: ") . Ansi::bold((string) $summary['totalVps']) .
             Ansi::gray(" | Server Nodes: ") . Ansi::bold((string) $summary['totalServers']) .
             Ansi::gray(" | Bound IPs: ") . Ansi::bold((string) $summary['totalIps']) . "\n";
        echo Ansi::hr() . "\n";
    }

    /**
     * Interactive paginated VPS listing
     */
    private function interactiveList(int $page = 1, int $limit = 15, ?string $search = null): void
    {
        while (true) {
            $result = $this->vpsService->getPagedList($page, $limit, $search);
            $this->renderVpsTable($result['items']);

            $title = sprintf("Page %d of %d (Total: %d VPS records)", $result['page'], $result['totalPages'], $result['total']);
            echo Ansi::bold(Ansi::brightCyan(" {$title} \n"));

            $actions = [];
            if ($result['page'] < $result['totalPages']) {
                $actions['n'] = 'Next page';
            }
            if ($result['page'] > 1) {
                $actions['p'] = 'Previous page';
            }
            $actions['g'] = 'Go to page';
            $actions['i'] = 'Inspect a VPS from this list';
            $actions['d'] = Ansi::brightRed('Delete a VPS from this list');
            $actions['s'] = 'Change search filter';
            $actions['m'] = 'Return to main menu';

            $choice = strtolower(Prompt::menu('PAGINATION & ACTIONS', $actions, ($result['page'] < $result['totalPages'] ? 'n' : 'm')));

            if ($choice === 'n' && $result['page'] < $result['totalPages']) {
                $page++;
            } elseif ($choice === 'p' && $result['page'] > 1) {
                $page--;
            } elseif ($choice === 'g') {
                $target = Prompt::ask("Enter page number (1-{$result['totalPages']})");
                if (is_numeric($target)) {
                    $page = (int) $target;
                }
            } elseif ($choice === 'i') {
                $vpsid = Prompt::ask("Enter VPS ID to inspect");
                if (is_numeric($vpsid)) {
                    $this->cmdInspect((int) $vpsid);
                    Prompt::pause();
                }
            } elseif ($choice === 'd') {
                $vpsid = Prompt::ask("Enter VPS ID to remove");
                if (is_numeric($vpsid)) {
                    $this->cmdDeleteWorkflow((int) $vpsid);
                    Prompt::pause();
                }
            } elseif ($choice === 's') {
                $newSearch = Prompt::ask("Enter new search query (or leave empty to clear)");
                $search = ($newSearch !== '') ? $newSearch : null;
                $page = 1;
            } elseif ($choice === 'm' || $choice === 'q') {
                break;
            }
        }
    }

    /**
     * Render formatted table of VPS instances
     *
     * @param array<Vps> $vpsList
     */
    private function renderVpsTable(array $vpsList): void
    {
        $headers = ['ID', 'VPS Name', 'Hostname', 'IP Address(es)', 'Server ID', 'Server Name', 'Disk Summary', 'Status'];
        $rows = [];

        foreach ($vpsList as $vps) {
            $serverName = $vps->getServer()?->getServerName() ?? 'N/A';
            $statusStr = $vps->getStatus();
            $statusBadge = ($statusStr === '1' || $statusStr === 'running' || $statusStr === 'active')
                ? Ansi::green('Active')
                : Ansi::gray('Offline/Unk');

            $rows[] = [
                Ansi::bold((string) $vps->getVpsid()),
                $vps->getVpsName(),
                $vps->getHostname() ?: Ansi::dim('-'),
                $vps->getAllIpsFormatted(),
                (string) $vps->getSerid(),
                $serverName,
                $vps->getDiskSummary(),
                $statusBadge,
            ];
        }

        $table = new Table($headers, $rows);
        $table->setMaxColWidth(2, 24); // Hostname
        $table->setMaxColWidth(3, 26); // IPs
        echo $table->render();
    }

    /**
     * CLI command: --list
     */
    public function cmdList(int $page = 1, int $limit = 20, ?string $query = null, ?int $server = null): int
    {
        $result = $this->vpsService->getPagedList($page, $limit, $query, $server);
        $this->renderVpsTable($result['items']);
        printf("Showing page %d of %d (%d total records)\n\n", $result['page'], $result['totalPages'], $result['total']);
        return 0;
    }

    /**
     * CLI command / Workflow STEP 1: Inspect VPS details
     */
    public function cmdInspect(int $vpsid): int
    {
        $vps = $this->vpsService->findById($vpsid);
        if ($vps === null) {
            Prompt::alert('danger', 'VPS Not Found', ["No record exists with VPS ID #{$vpsid} in the database."]);
            return 1;
        }

        echo "\n" . Ansi::bold(Ansi::brightCyan("═══ STEP 1: DETAILED VPS INSPECTION (ID #{$vpsid}) ═══")) . "\n\n";

        $server = $vps->getServer();
        $serverStatus = $server ? ($server->isOnline() ? Ansi::green('Online (1)') : Ansi::red('Offline (0)')) : Ansi::yellow('Unknown');

        // General Information Table
        $infoTable = new Table(['Property', 'Value']);
        $infoTable->addRow(['VPS ID (vpsid)', (string) $vps->getVpsid()]);
        $infoTable->addRow(['VPS Name', $vps->getVpsName()]);
        $infoTable->addRow(['UUID', $vps->getUuid()]);
        $infoTable->addRow(['Hostname', $vps->getHostname() ?: 'Not set']);
        $infoTable->addRow(['OS Template', $vps->getOsName()]);
        $infoTable->addRow(['User / Owner ID', (string) $vps->getUid()]);
        $infoTable->addRow(['RAM / Cores / Space', "{$vps->getRam()} MB / {$vps->getCores()} Core(s) / {$vps->getSpace()} GB"]);
        $infoTable->addRow(['Server Node ID', (string) $vps->getSerid()]);
        $infoTable->addRow(['Server Name', $server?->getServerName() ?? 'N/A']);
        $infoTable->addRow(['Server IP', $server?->getIp() ?? 'N/A']);
        $infoTable->addRow(['Server Status', $serverStatus]);
        echo $infoTable->render();

        // Disk Metadata Table
        echo Ansi::bold("Associated Disk Metadata:") . "\n";
        $disks = $vps->getDisks();
        if (empty($disks)) {
            echo Ansi::dim("  (No disk records found in 'disks' table for UUID {$vps->getUuid()})\n\n");
        } else {
            $diskTable = new Table(['Disk ID (did)', 'Type', 'Size', 'Primary', 'Host Path (PHYSICAL FILE)']);
            foreach ($disks as $d) {
                $diskTable->addRow([
                    (string) $d->getDid(),
                    $d->getType(),
                    $d->getSize() . ' GB',
                    $d->isPrimary() ? 'Yes' : 'No',
                    $d->getPath() . Ansi::dim(' [PHYSICAL FILE UNTOUCHED]'),
                ]);
            }
            echo $diskTable->render();
        }

        // Assigned IP Table
        echo Ansi::bold("Assigned IP Addresses:") . "\n";
        $ips = $vps->getIps();
        if (empty($ips)) {
            echo Ansi::dim("  (No assigned IP records found in 'ips' table)\n\n");
        } else {
            $ipTable = new Table(['IP ID', 'IPv4', 'IPv6', 'MAC Address', 'Pool / Server ID', 'Reserved / Locked']);
            foreach ($ips as $ip) {
                $ipTable->addRow([
                    (string) $ip->getIpid(),
                    $ip->getIp(),
                    $ip->getIpv6() ?? Ansi::dim('None'),
                    $ip->getMac() ?? Ansi::dim('None'),
                    (string) $ip->getSerid(),
                    $ip->isLocked() ? Ansi::yellow('Yes (Reserved)') : 'No',
                ]);
            }
            echo $ipTable->render();
        }

        // Tasks / Actions
        $tasks = $vps->getTasks();
        if (!empty($tasks)) {
            echo Ansi::bold("Recent Tasks & Actions:") . "\n";
            $taskTable = new Table(['Task ID', 'Action', 'Status', 'Date']);
            foreach ($tasks as $t) {
                $taskStatus = match ($t->getStatus()) {
                    0 => Ansi::brightYellow('IN PROGRESS / PENDING'),
                    1 => Ansi::green('Completed'),
                    default => Ansi::red('Error/Failed'),
                };
                $taskTable->addRow([
                    (string) $t->getTaskId(),
                    $t->getAction(),
                    $taskStatus,
                    date('Y-m-d H:i:s', $t->getTime()),
                ]);
            }
            echo $taskTable->render();
        }

        // Native API Investigation
        $apiCheck = $this->apiService->investigateApiDeletion($vps);
        echo Ansi::bold("Virtualizor API Investigation:") . "\n";
        echo "  " . Ansi::cyan($apiCheck['reason']) . "\n";
        foreach ($apiCheck['details'] as $det) {
            echo "  " . Ansi::dim("• " . $det) . "\n";
        }
        echo "\n";

        return 0;
    }

    /**
     * CLI command: --dry-run --delete <vpsid>
     */
    public function cmdDryRun(int $vpsid): int
    {
        $vps = $this->vpsService->findById($vpsid);
        if ($vps === null) {
            Prompt::alert('danger', 'VPS Not Found', ["No record exists with VPS ID #{$vpsid} in the database."]);
            return 1;
        }

        $this->cmdInspect($vpsid);

        $dryRun = $this->cleanupService->generateDryRunReport($vps);
        $this->displayDryRunReport($dryRun);

        return 0;
    }

    /**
     * Render the Step 3 Dry-Run Report
     *
     * @param array<string, mixed> $report
     */
    private function displayDryRunReport(array $report): void
    {
        echo "\n" . Ansi::bold(Ansi::brightYellow("═══ STEP 3: DRY RUN PREVIEW (NO CHANGES COMMITTED) ═══")) . "\n\n";

        echo Ansi::bold("1. Database Records to be Deleted:") . "\n";
        echo "   • Target: Table `vps` -> Row ID: " . Ansi::bold((string) $report['vpsToDelete']['vpsid']) .
             " (Name: {$report['vpsToDelete']['vps_name']}, UUID: {$report['vpsToDelete']['uuid']})\n";

        if (!empty($report['disksToDelete'])) {
            echo "   • Target: Table `disks` -> " . count($report['disksToDelete']) . " record(s) matching UUID:\n";
            foreach ($report['disksToDelete'] as $d) {
                echo "     - Disk #{$d['did']}: {$d['path']} ({$d['size']} GB, {$d['type']})\n";
            }
        } else {
            echo "   • Target: Table `disks` -> No disk metadata records to delete.\n";
        }

        echo "\n" . Ansi::bold("2. Database Records to be Disassociated / Preserved:") . "\n";
        if (!empty($report['ipsToModify'])) {
            echo "   • Target: Table `ips` -> " . count($report['ipsToModify']) . " assigned IP(s) will be unlinked (set vpsid = 0):\n";
            foreach ($report['ipsToModify'] as $ip) {
                echo "     - IP #{$ip['ipid']} ({$ip['ip']}): vpsid reset to 0; IP kept reserved in pool.\n";
            }
        } else {
            echo "   • Target: Table `ips` -> No IP records assigned to this VPS.\n";
        }

        echo "\n" . Ansi::bold("3. Planned SQL Execution Commands:") . "\n";
        foreach ($report['sqlStatements'] as $sql) {
            echo "   " . Ansi::cyan($sql) . "\n";
        }

        echo "\n" . Ansi::bold(Ansi::brightGreen("4. What Will Remain UNTOUCHED:")) . "\n";
        foreach ($report['untouched'] as $item) {
            echo "   " . Ansi::green("✓ ") . $item . "\n";
        }
        echo "\n";
    }

    /**
     * Complete 7-step guarded deletion workflow
     */
    public function cmdDeleteWorkflow(int $vpsid): int
    {
        // STEP 1: INSPECTION
        $vps = $this->vpsService->findById($vpsid);
        if ($vps === null) {
            Prompt::alert('danger', 'VPS Not Found', ["No record exists with VPS ID #{$vpsid} in the database."]);
            return 1;
        }

        $this->cmdInspect($vpsid);

        // STEP 2: SAFETY VALIDATION
        echo Ansi::bold(Ansi::brightCyan("═══ STEP 2: SAFETY VALIDATION ═══")) . "\n";
        $validation = $this->cleanupService->validateSafety($vps);

        if (!empty($validation['warnings'])) {
            Prompt::alert('warning', 'Safety Considerations', $validation['warnings']);
        }

        if (!$validation['safe']) {
            Prompt::alert('danger', 'Deletion Blocked by Safety Rules', array_merge(
                ["The cleanup manager refused to proceed due to the following safety blockers:"],
                $validation['blockers'],
                ["", "Resolve these conditions or abort."]
            ));
            return 1;
        }

        // Ask explicit confirmation that VPS belongs to retired infrastructure
        $serverName = $vps->getServer()?->getServerName() ?? "Server #{$vps->getSerid()}";
        $confirmRetired = Prompt::confirm(
            "Do you explicitly confirm that VPS #{$vpsid} ({$vps->getVpsName()}) belongs to retired infrastructure ({$serverName}) and its data is no longer required?",
            false
        );

        if (!$confirmRetired) {
            echo "\n" . Ansi::yellow("Operation cancelled by user. No database modifications made.") . "\n\n";
            return 0;
        }

        // STEP 3: DRY RUN
        $dryRun = $this->cleanupService->generateDryRunReport($vps);
        $this->displayDryRunReport($dryRun);

        $proceedToBackup = Prompt::confirm("Proceed to Step 4 (Automatic Full Database Backup)?", false);
        if (!$proceedToBackup) {
            echo "\n" . Ansi::yellow("Operation cancelled at dry-run stage. No changes made.") . "\n\n";
            return 0;
        }

        // STEP 4: DATABASE BACKUP
        echo "\n" . Ansi::bold(Ansi::brightCyan("═══ STEP 4: DATABASE BACKUP ═══")) . "\n";
        echo "Creating timestamped consistent backup before making ANY database modifications...\n";

        try {
            $backupPath = $this->backupService->createPreDeletionBackup($vpsid);
            $backupSize = $this->backupService->formatBytes(filesize($backupPath) ?: 0);

            Prompt::alert('success', 'Backup Created & Verified Successfully', [
                "Backup Location: {$backupPath}",
                "Backup Size: {$backupSize}",
                "Permissions: 0600 (owner read/write only)",
                "",
                "Disaster Recovery Command:",
                $this->backupService->getRecoveryCommand($backupPath),
            ]);
        } catch (Throwable $e) {
            Prompt::alert('danger', 'Backup Failed - OPERATION ABORTED IMMEDIATELY', [
                "Could not create or validate the pre-deletion database backup.",
                "Error: " . $e->getMessage(),
                "",
                "CRITICAL: Zero database changes were made. Deletion aborted for data safety.",
            ]);
            return 1;
        }

        // STEP 5: EXPLICIT CONFIRMATION PHRASE
        echo Ansi::bold(Ansi::brightCyan("═══ STEP 5: EXPLICIT CONFIRMATION ═══")) . "\n";
        $expectedPhrase = sprintf("DELETE %d %s", $vpsid, $vps->getVpsName());

        $confirmed = Prompt::confirmPhrase(
            "This will permanently delete the database record for VPS #{$vpsid} ({$vps->getVpsName()}).",
            $expectedPhrase
        );

        if (!$confirmed) {
            echo "\n" . Ansi::yellow("Confirmation phrase did not match. Operation cancelled safely.") . "\n";
            echo Ansi::dim("Backup is preserved at: {$backupPath}\n\n");
            return 0;
        }

        // STEP 6: GUARDED CLEANUP
        echo "\n" . Ansi::bold(Ansi::brightCyan("═══ STEP 6: GUARDED CLEANUP EXECUTION ═══")) . "\n";
        echo "Locking tables, revalidating records, and performing targeted modifications...\n";

        $result = $this->cleanupService->executeCleanup($vps, $backupPath);

        // STEP 7: RESULTS
        echo "\n" . Ansi::bold(Ansi::brightCyan("═══ STEP 7: CLEANUP RESULTS ═══")) . "\n\n";

        if ($result['success'] && $result['verifiedDeleted']) {
            Prompt::alert('success', 'VPS Cleanup Completed Successfully', [
                "Target VPS ID: #{$vpsid} ({$vps->getVpsName()})",
                "Disk Metadata Records Removed: {$result['unlinkedDisks']}",
                "IP Addresses Unlinked & Reserved: {$result['unlinkedIps']}",
                "Post-Deletion Verification: PASSED (Record confirmed absent from 'vps' table)",
                "Physical Disk Files: UNTOUCHED",
                "Pre-Deletion Backup: {$backupPath}",
                "",
                "Audit log entry recorded.",
            ]);
            return 0;
        } else {
            Prompt::alert('danger', 'Guarded Cleanup Failed', [
                "An error occurred while modifying database records:",
                $result['error'] ?? 'Unknown error',
                "",
                "IMPORTANT RECOVERY INSTRUCTIONS (MyISAM):",
                "Because MyISAM tables do not support transactional rollback, some partial changes may exist.",
                "To restore the database to its exact pre-operation state, run:",
                $this->backupService->getRecoveryCommand($backupPath),
            ]);
            return 1;
        }
    }

    /**
     * View backup history
     */
    public function cmdBackups(): int
    {
        echo "\n" . Ansi::bold(Ansi::brightCyan("═══ DATABASE BACKUP HISTORY ═══")) . "\n\n";
        echo Ansi::dim("Backup Directory: " . $this->config->getBackupDir()) . "\n\n";

        $backups = $this->backupService->listBackups();
        if (empty($backups)) {
            echo Ansi::dim("  No database backups found in the backup directory.\n\n");
            return 0;
        }

        $table = new Table(['Filename', 'Size', 'Created At', 'Full Path']);
        foreach ($backups as $b) {
            $table->addRow([
                $b['filename'],
                $b['size'],
                $b['mtime'],
                $b['path'],
            ]);
        }
        $table->setMaxColWidth(3, 40);
        echo $table->render();

        echo Ansi::dim("Note: Backups are never automatically purged without explicit user confirmation.\n\n");
        return 0;
    }

    /**
     * View cleanup audit history
     */
    public function cmdHistory(): int
    {
        echo "\n" . Ansi::bold(Ansi::brightCyan("═══ CLEANUP AUDIT HISTORY ═══")) . "\n\n";
        echo Ansi::dim("Audit Log: " . $this->logger->getAuditJsonFile()) . "\n\n";

        $records = $this->logger->getRecentAuditHistory(25);
        if (empty($records)) {
            echo Ansi::dim("  No audit records logged yet.\n\n");
            return 0;
        }

        $table = new Table(['Timestamp', 'Action', 'Operator', 'VPS ID / Details']);
        foreach ($records as $r) {
            $details = '';
            if (isset($r['vpsid'])) {
                $details .= "VPS #{$r['vpsid']}";
            }
            if (isset($r['vps_name'])) {
                $details .= " ({$r['vps_name']})";
            }
            if (isset($r['size'])) {
                $details .= " Size: {$r['size']}";
            }

            $table->addRow([
                $r['timestamp'] ?? 'N/A',
                $r['action'] ?? 'N/A',
                $r['operator'] ?? 'unknown',
                $details ?: json_encode($r, JSON_UNESCAPED_SLASHES),
            ]);
        }
        echo $table->render();
        return 0;
    }

    /**
     * Inspect database schema and table engines
     */
    public function cmdSchema(): int
    {
        echo "\n" . Ansi::bold(Ansi::brightCyan("═══ DATABASE SCHEMA & ENGINE INSPECTION ═══")) . "\n\n";

        $engines = $this->inspector->getTableEngines();
        $table = new Table(['Table Name', 'Storage Engine', 'Status']);

        foreach ($this->inspector->getTables() as $tbl) {
            $eng = $engines[$tbl] ?? 'Unknown';
            $isMyIsam = (strcasecmp($eng, 'MyISAM') === 0);
            $status = $isMyIsam
                ? Ansi::yellow('MyISAM (Requires Table Locks)')
                : Ansi::green('Transactional / Safe');

            $table->addRow([$tbl, $eng, $status]);
        }
        echo $table->render();

        $validation = $this->inspector->validateSchema();
        if (!empty($validation['warnings'])) {
            Prompt::alert('warning', 'Schema Notices', $validation['warnings']);
        }
        if (!$validation['valid']) {
            Prompt::alert('danger', 'Schema Validation Errors', $validation['errors']);
        }

        return 0;
    }

    /**
     * Parse command line arguments
     *
     * @param array<string> $argv
     * @return array<string, mixed>
     */
    private function parseArguments(array $argv): array
    {
        $args = [];
        $count = count($argv);

        for ($i = 1; $i < $count; $i++) {
            $arg = $argv[$i];

            if (str_starts_with($arg, '--')) {
                $name = substr($arg, 2);
                if (str_contains($name, '=')) {
                    [$key, $val] = explode('=', $name, 2);
                    $args[$key] = $val;
                } elseif ($i + 1 < $count && !str_starts_with($argv[$i + 1], '-')) {
                    $args[$name] = $argv[++$i];
                } else {
                    $args[$name] = true;
                }
            } elseif (str_starts_with($arg, '-')) {
                $flag = substr($arg, 1);
                if ($i + 1 < $count && !str_starts_with($argv[$i + 1], '-')) {
                    $args[$flag] = $argv[++$i];
                } else {
                    $args[$flag] = true;
                }
            }
        }

        return $args;
    }

    /**
     * Display CLI help documentation
     */
    public function printHelp(): void
    {
        Prompt::banner();
        echo "Description:\n";
        echo "  A production-quality interactive command-line utility built for Linux systems\n";
        echo "  administrators and hosting providers to safely inspect, preview, and remove\n";
        echo "  orphaned VPS database records from Virtualizor master servers when slave nodes\n";
        echo "  have been permanently decommissioned, wiped, or reinstalled.\n\n";
        echo "Usage: \n";
        echo "  php virtualizor-vps-cleaner.php [options]\n\n";
        echo "Options:\n";
        echo "  " . str_pad("--list", 28) . "List VPS instances with pagination\n";
        echo "  " . str_pad("--page <N>", 28) . "Page number for --list (default: 1)\n";
        echo "  " . str_pad("--limit <N>", 28) . "Number of records per page (default: 20)\n";
        echo "  " . str_pad("--server <serid>", 28) . "Filter listing by server ID\n";
        echo "  " . str_pad("--search <query>", 28) . "Search VPS by ID, name, hostname, or IP address\n";
        echo "  " . str_pad("--inspect <vpsid>", 28) . "Inspect complete VPS details, disks, IPs, and tasks\n";
        echo "  " . str_pad("--dry-run --delete <id>", 28) . "Simulate cleanup without modifying database or making backup\n";
        echo "  " . str_pad("--delete <vpsid>", 28) . "Run 7-step guarded cleanup workflow for a specific VPS\n";
        echo "  " . str_pad("--backups", 28) . "List all pre-deletion database backups\n";
        echo "  " . str_pad("--history", 28) . "Display recent cleanup audit log history\n";
        echo "  " . str_pad("--schema", 28) . "Inspect database tables and MyISAM storage engine status\n";
        echo "  " . str_pad("--config <file>", 28) . "Path to custom Virtualizor universal.php or config file\n";
        echo "  " . str_pad("--no-ansi", 28) . "Disable ANSI color formatting\n";
        echo "  " . str_pad("-h, --help", 28) . "Show this help screen\n";
        echo "  " . str_pad("-v, --version", 28) . "Display version information\n\n";
        echo "Interactive Mode:\n";
        echo "  Running without arguments launches the interactive terminal interface.\n\n";
        echo "Safety Guarantees:\n";
        echo "  • Listing or inspection NEVER executes destructive operations.\n";
        echo "  • Physical disk files (qcow2, raw, zvol) are NEVER deleted or touched.\n";
        echo "  • Every deletion requires a verified consistent database backup first.\n";
        echo "  • Strict table locking prevents race conditions on MyISAM databases.\n";
        echo "  • Explicit confirmation phrase required for every individual deletion.\n\n";
    }
}

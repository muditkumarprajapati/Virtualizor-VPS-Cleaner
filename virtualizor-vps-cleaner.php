#!/usr/bin/env php
<?php
/**
 * Virtualizor VPS Cleanup Manager
 *
 * Production-quality interactive command-line utility to safely remove
 * orphaned VPS database records from retired or reinstalled infrastructure.
 *
 * @package VirtualizorVpsCleaner
 * @author  Mudit Kumar Prajapati
 * @link    https://github.com/muditkumarprajapati/virtualizor-vps-cleaner
 * @license MIT
 */

declare(strict_types=1);

// 1. PHP Version and Environment Compatibility Checks
if (PHP_VERSION_ID < 70400) {
    fwrite(STDERR, "Error: Virtualizor VPS Cleanup Manager requires PHP 7.4.0 or newer.\n");
    fwrite(STDERR, "Current PHP version: " . PHP_VERSION . "\n");
    fwrite(STDERR, "Tip: On Virtualizor master servers, you can execute using the EMPS PHP binary:\n");
    fwrite(STDERR, "  /usr/local/emps/bin/php virtualizor-vps-cleaner.php\n\n");
    exit(1);
}

// 2. Check required PHP extensions
$requiredExtensions = ['pdo', 'json', 'mbstring'];
$missing = [];
foreach ($requiredExtensions as $ext) {
    if (!extension_loaded($ext)) {
        $missing[] = $ext;
    }
}

if (!empty($missing)) {
    fwrite(STDERR, "Error: Missing required PHP extension(s): " . implode(', ', $missing) . "\n");
    fwrite(STDERR, "Please install or enable them in your php.ini.\n");
    exit(1);
}

// 3. Register PSR-4 Autoloader
require_once __DIR__ . '/src/Autoloader.php';
\VirtualizorVpsCleaner\Autoloader::register(__DIR__ . '/src');

use VirtualizorVpsCleaner\Config\VirtualizorConfig;
use VirtualizorVpsCleaner\Cli\App;

// 4. Extract custom config path if supplied in arguments, and check for help/version flags early
$customConfig = null;
$showHelp = false;
$showVersion = false;

foreach ($argv as $i => $arg) {
    if ($arg === '--help' || $arg === '-h') {
        $showHelp = true;
    } elseif ($arg === '--version' || $arg === '-v') {
        $showVersion = true;
    } elseif (str_starts_with($arg, '--config=')) {
        $customConfig = substr($arg, 9);
    } elseif ($arg === '--config' && isset($argv[$i + 1])) {
        $customConfig = $argv[$i + 1];
    }
}

if ($showHelp) {
    \VirtualizorVpsCleaner\Terminal\Prompt::banner();
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
    exit(0);
}

if ($showVersion) {
    echo "Virtualizor VPS Cleanup Manager version " . \VirtualizorVpsCleaner\Cli\App::VERSION . " (PHP " . PHP_VERSION . ")\n";
    exit(0);
}

// 5. Load configuration
try {
    $config = VirtualizorConfig::load($customConfig);
} catch (\Throwable $e) {
    fwrite(STDERR, "Configuration Error: " . $e->getMessage() . "\n");
    fwrite(STDERR, "Please specify a valid Virtualizor configuration using --config /path/to/universal.php\n");
    fwrite(STDERR, "or set the environment variables (VIRTUALIZOR_DB_HOST, VIRTUALIZOR_DB_USER, etc).\n");
    exit(1);
}

// 6. Launch Application
$app = new App($config);
$exitCode = $app->run($argv);
exit($exitCode);

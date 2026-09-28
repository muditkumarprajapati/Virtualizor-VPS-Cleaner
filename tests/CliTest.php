<?php
/**
 * Virtualizor VPS Cleanup Manager
 *
 * Command Line Interface Test Suite
 *
 * @package VirtualizorVpsCleaner\Tests
 * @author  Mudit Kumar Prajapati
 * @license MIT
 */

namespace VirtualizorVpsCleaner\Tests;

use VirtualizorVpsCleaner\Cli\App;

class CliTest extends TestCase
{
    private App $app;

    public function setUp(): void
    {
        parent::setUp();
        $this->app = new App($this->config);
    }

    public function testListCommand(): void
    {
        ob_start();
        $code = $this->app->run(['virtualizor-vps-cleaner.php', '--list', '--limit=5', '--no-ansi']);
        $output = ob_get_clean();

        $this->assertEquals(0, $code);
        $this->assertStringContains('v1001', $output);
        $this->assertStringContains('v1002', $output);
    }

    public function testSearchCommand(): void
    {
        ob_start();
        $code = $this->app->run(['virtualizor-vps-cleaner.php', '--search=old-vm1', '--no-ansi']);
        $output = ob_get_clean();

        $this->assertEquals(0, $code);
        $this->assertStringContains('v1001', $output);
        $this->assertFalse(str_contains($output, 'v1002'));
    }

    public function testInspectCommand(): void
    {
        ob_start();
        $code = $this->app->run(['virtualizor-vps-cleaner.php', '--inspect=101', '--no-ansi']);
        $output = ob_get_clean();

        $this->assertEquals(0, $code);
        $this->assertStringContains('STEP 1: DETAILED VPS INSPECTION', $output);
        $this->assertStringContains('uuid-orphaned-101', $output);
        $this->assertStringContains('/var/virtualizor/kvm/v1001.img', $output);
    }

    public function testDryRunCommand(): void
    {
        ob_start();
        $code = $this->app->run(['virtualizor-vps-cleaner.php', '--dry-run', '--delete=101', '--no-ansi']);
        $output = ob_get_clean();

        $this->assertEquals(0, $code);
        $this->assertStringContains('STEP 3: DRY RUN PREVIEW', $output);
        $this->assertStringContains('DELETE FROM `vps` WHERE `vpsid` = 101', $output);

        // Verify record is still intact
        $count = (int) $this->connection->getPdo()->query("SELECT COUNT(*) FROM `vps` WHERE `vpsid` = 101")->fetchColumn();
        $this->assertEquals(1, $count);
    }

    public function testSchemaCommand(): void
    {
        ob_start();
        $code = $this->app->run(['virtualizor-vps-cleaner.php', '--schema', '--no-ansi']);
        $output = ob_get_clean();

        $this->assertEquals(0, $code);
        $this->assertStringContains('DATABASE SCHEMA & ENGINE INSPECTION', $output);
        $this->assertStringContains('vps', $output);
        $this->assertStringContains('disks', $output);
    }

    public function testBackupsCommand(): void
    {
        ob_start();
        $code = $this->app->run(['virtualizor-vps-cleaner.php', '--backups', '--no-ansi']);
        $output = ob_get_clean();

        $this->assertEquals(0, $code);
        $this->assertStringContains('DATABASE BACKUP HISTORY', $output);
    }

    public function testHistoryCommand(): void
    {
        ob_start();
        $code = $this->app->run(['virtualizor-vps-cleaner.php', '--history', '--no-ansi']);
        $output = ob_get_clean();

        $this->assertEquals(0, $code);
        $this->assertStringContains('CLEANUP AUDIT HISTORY', $output);
    }
}

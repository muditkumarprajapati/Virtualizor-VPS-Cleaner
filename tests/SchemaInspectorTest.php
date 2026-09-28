<?php
/**
 * Virtualizor VPS Cleanup Manager
 *
 * Schema Inspector Test Suite
 *
 * @package VirtualizorVpsCleaner\Tests
 * @author  Mudit Kumar Prajapati
 * @license MIT
 */

namespace VirtualizorVpsCleaner\Tests;

class SchemaInspectorTest extends TestCase
{
    public function testGetTables(): void
    {
        $tables = $this->inspector->getTables();
        $this->assertTrue($this->inspector->hasTable('vps'));
        $this->assertTrue($this->inspector->hasTable('disks'));
        $this->assertTrue($this->inspector->hasTable('ips'));
        $this->assertTrue($this->inspector->hasTable('servers'));
        $this->assertTrue($this->inspector->hasTable('tasks'));
    }

    public function testGetColumns(): void
    {
        $vpsCols = $this->inspector->getColumns('vps');
        $this->assertTrue(in_array('vpsid', $vpsCols, true));
        $this->assertTrue(in_array('vps_name', $vpsCols, true));
        $this->assertTrue(in_array('uuid', $vpsCols, true));
        $this->assertTrue(in_array('serid', $vpsCols, true));
        $this->assertTrue(in_array('hostname', $vpsCols, true));

        $this->assertTrue($this->inspector->hasColumn('vps', 'vpsid'));
        $this->assertFalse($this->inspector->hasColumn('vps', 'non_existent_column'));
    }

    public function testSchemaValidationSuccess(): void
    {
        $validation = $this->inspector->validateSchema();
        $this->assertTrue($validation['valid']);
        $this->assertCount(0, $validation['errors']);
    }

    public function testSchemaValidationFailsOnMissingTable(): void
    {
        // Drop disks table to simulate corrupted schema
        $this->connection->getPdo()->exec("DROP TABLE `disks`");

        // Force reload
        $freshInspector = new \VirtualizorVpsCleaner\Database\SchemaInspector($this->connection);
        $validation = $freshInspector->validateSchema();

        $this->assertFalse($validation['valid']);
        $this->assertTrue(count($validation['errors']) > 0);
        $this->assertStringContains("disks", $validation['errors'][0]);
    }
}

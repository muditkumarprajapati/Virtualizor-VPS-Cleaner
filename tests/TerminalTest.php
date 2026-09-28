<?php
/**
 * Virtualizor VPS Cleanup Manager
 *
 * Terminal ANSI and Table Test Suite
 *
 * @package VirtualizorVpsCleaner\Tests
 * @author  Mudit Kumar Prajapati
 * @license MIT
 */

namespace VirtualizorVpsCleaner\Tests;

use VirtualizorVpsCleaner\Terminal\Ansi;
use VirtualizorVpsCleaner\Terminal\Table;

class TerminalTest extends TestCase
{
    public function testAnsiStripAndLength(): void
    {
        Ansi::setEnabled(true);
        $styled = Ansi::bold(Ansi::red("Hello World"));
        $this->assertEquals("Hello World", Ansi::strip($styled));
        $this->assertEquals(11, Ansi::length($styled));
    }

    public function testTableRendering(): void
    {
        $table = new Table(['ID', 'Name'], [
            ['1', 'Alpha'],
            ['2', 'Beta'],
        ]);

        $output = $table->render();
        $this->assertStringContains('ID', $output);
        $this->assertStringContains('Alpha', $output);
        $this->assertStringContains('Beta', $output);
    }

    public function testEmptyTableRendering(): void
    {
        $table = new Table(['Column 1', 'Column 2'], []);
        $output = $table->render();
        $this->assertStringContains('No records found', $output);
    }
}

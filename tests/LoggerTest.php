<?php
/**
 * Virtualizor VPS Cleanup Manager
 *
 * Logger and Sensitive Redaction Test Suite
 *
 * @package VirtualizorVpsCleaner\Tests
 * @author  Mudit Kumar Prajapati
 * @license MIT
 */

namespace VirtualizorVpsCleaner\Tests;

class LoggerTest extends TestCase
{
    public function testSensitiveDataRedaction(): void
    {
        $this->logger->info("Testing connection to database", [
            'user'     => 'root',
            'password' => 'ConfidentialPass!@#',
            'token'    => 'my_secret_api_token',
        ]);

        $logContent = file_get_contents($this->logger->getLogFile());
        $this->assertFalse(str_contains($logContent, 'ConfidentialPass!@#'));
        $this->assertFalse(str_contains($logContent, 'my_secret_api_token'));
        $this->assertTrue(str_contains($logContent, '********'));
    }

    public function testAuditLoggingAndHistoryRetrieval(): void
    {
        $this->logger->audit('TEST_DELETION', [
            'vpsid'    => 101,
            'vps_name' => 'v1001',
        ]);

        $history = $this->logger->getRecentAuditHistory(10);
        $this->assertTrue(count($history) > 0);
        $this->assertEquals('TEST_DELETION', $history[0]['action']);
        $this->assertEquals(101, $history[0]['vpsid']);
    }
}

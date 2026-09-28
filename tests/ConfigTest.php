<?php
/**
 * Virtualizor VPS Cleanup Manager
 *
 * Config Test Suite
 *
 * @package VirtualizorVpsCleaner\Tests
 * @author  Mudit Kumar Prajapati
 * @license MIT
 */

namespace VirtualizorVpsCleaner\Tests;

use VirtualizorVpsCleaner\Config\VirtualizorConfig;
use RuntimeException;
use InvalidArgumentException;

class ConfigTest extends TestCase
{
    public function testParseMockUniversalFile(): void
    {
        $mockFile = $this->tempDir . '/universal_test.php';
        file_put_contents($mockFile, "<?php
            \$globals['dbhost'] = '127.0.0.1';
            \$globals['dbuser'] = 'vps_user';
            \$globals['dbpass'] = 's3cr3t_p@ss';
            \$globals['dbname'] = 'vps_db';
            \$globals['dbport'] = 3307;
            \$globals['dbsocket'] = '/var/run/mysqld/mysqld.sock';
        ");

        $parsed = VirtualizorConfig::parseUniversalFile($mockFile);

        $this->assertEquals('127.0.0.1', $parsed['host']);
        $this->assertEquals('vps_user', $parsed['user']);
        $this->assertEquals('s3cr3t_p@ss', $parsed['pass']);
        $this->assertEquals('vps_db', $parsed['name']);
        $this->assertEquals(3307, $parsed['port']);
        $this->assertEquals('/var/run/mysqld/mysqld.sock', $parsed['socket']);
    }

    public function testSanitizedInfoMasksPassword(): void
    {
        $config = new VirtualizorConfig([
            'host' => '10.0.0.5',
            'user' => 'admin',
            'pass' => 'SuperSecretPassword123!',
            'name' => 'virtualizor',
        ]);

        $sanitized = $config->getSanitizedInfo();

        $this->assertEquals('10.0.0.5', $sanitized['db_host']);
        $this->assertEquals('admin', $sanitized['db_user']);
        $this->assertEquals('******** (masked)', $sanitized['db_pass']);
        $this->assertFalse(str_contains(json_encode($sanitized), 'SuperSecretPassword123!'));
    }

    public function testParseNonExistentFileThrowsException(): void
    {
        $nonExistent = $this->tempDir . '/non_existent_file.php';
        $threw = false;
        try {
            VirtualizorConfig::parseUniversalFile($nonExistent);
        } catch (InvalidArgumentException $e) {
            $threw = true;
        }

        $this->assertTrue($threw, "Expected InvalidArgumentException for missing file.");
    }
}

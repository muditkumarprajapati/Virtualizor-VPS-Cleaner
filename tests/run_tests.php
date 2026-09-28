#!/usr/bin/env php
<?php
/**
 * Virtualizor VPS Cleanup Manager
 *
 * Standalone Zero-Dependency Automated Test Runner
 *
 * @package VirtualizorVpsCleaner\Tests
 * @author  Mudit Kumar Prajapati
 * @license MIT
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Autoloader.php';
\VirtualizorVpsCleaner\Autoloader::register(dirname(__DIR__) . '/src');

require_once __DIR__ . '/TestCase.php';
require_once __DIR__ . '/ConfigTest.php';
require_once __DIR__ . '/DatabaseTest.php';
require_once __DIR__ . '/SchemaInspectorTest.php';
require_once __DIR__ . '/VpsServiceTest.php';
require_once __DIR__ . '/CleanupServiceTest.php';
require_once __DIR__ . '/BackupServiceTest.php';
require_once __DIR__ . '/LoggerTest.php';
require_once __DIR__ . '/TerminalTest.php';
require_once __DIR__ . '/CliTest.php';

use VirtualizorVpsCleaner\Terminal\Ansi;

echo "\n" . Ansi::bold(Ansi::brightCyan("==================================================================")) . "\n";
echo Ansi::bold(Ansi::brightWhite("           VIRTUALIZOR VPS CLEANUP MANAGER - TEST SUITE            ")) . "\n";
echo Ansi::bold(Ansi::brightCyan("==================================================================")) . "\n\n";

$testClasses = [
    \VirtualizorVpsCleaner\Tests\ConfigTest::class,
    \VirtualizorVpsCleaner\Tests\DatabaseTest::class,
    \VirtualizorVpsCleaner\Tests\SchemaInspectorTest::class,
    \VirtualizorVpsCleaner\Tests\VpsServiceTest::class,
    \VirtualizorVpsCleaner\Tests\CleanupServiceTest::class,
    \VirtualizorVpsCleaner\Tests\BackupServiceTest::class,
    \VirtualizorVpsCleaner\Tests\LoggerTest::class,
    \VirtualizorVpsCleaner\Tests\TerminalTest::class,
    \VirtualizorVpsCleaner\Tests\CliTest::class,
];

$totalTests = 0;
$passed = 0;
$failed = 0;
$errors = [];

$startTime = microtime(true);

foreach ($testClasses as $testClass) {
    $reflection = new ReflectionClass($testClass);
    $shortName = $reflection->getShortName();
    echo Ansi::bold(Ansi::yellow("Running {$shortName}...")) . "\n";

    $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);
    foreach ($methods as $method) {
        if (!str_starts_with($method->getName(), 'test')) {
            continue;
        }

        $testName = $method->getName();
        $totalTests++;

        /** @var \VirtualizorVpsCleaner\Tests\TestCase $instance */
        $instance = new $testClass();

        try {
            $instance->setUp();
            $instance->$testName();
            $instance->tearDown();

            echo "  " . Ansi::green("✓") . " " . Ansi::dim($testName) . "\n";
            $passed++;
        } catch (\Throwable $e) {
            echo "  " . Ansi::red("✖") . " " . Ansi::bold(Ansi::brightRed($testName)) . "\n";
            $failed++;
            $errors[] = [
                'class'   => $shortName,
                'method'  => $testName,
                'message' => $e->getMessage(),
                'file'    => $e->getFile() . ':' . $e->getLine(),
                'trace'   => $e->getTraceAsString(),
            ];
            try {
                $instance->tearDown();
            } catch (\Throwable $t) {
                // Ignore cleanup error after failure
            }
        }
    }
    echo "\n";
}

$elapsed = round(microtime(true) - $startTime, 3);

echo Ansi::bold(Ansi::brightCyan("------------------------------------------------------------------")) . "\n";
if ($failed === 0) {
    echo Ansi::bold(Ansi::brightGreen("PASS: All {$totalTests} automated tests passed successfully! ({$elapsed}s)")) . "\n";
    echo Ansi::bold(Ansi::brightCyan("------------------------------------------------------------------")) . "\n\n";
    exit(0);
} else {
    echo Ansi::bold(Ansi::brightRed("FAIL: {$failed} test(s) failed out of {$totalTests}. ({$elapsed}s)")) . "\n\n";
    foreach ($errors as $err) {
        echo Ansi::red("• {$err['class']}::{$err['method']}") . "\n";
        echo "  " . Ansi::brightYellow($err['message']) . "\n";
        echo "  " . Ansi::dim($err['file']) . "\n\n";
    }
    echo Ansi::bold(Ansi::brightCyan("------------------------------------------------------------------")) . "\n\n";
    exit(1);
}

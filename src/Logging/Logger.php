<?php
/**
 * Virtualizor VPS Cleanup Manager
 *
 * Structured Audit Logger with Sensitive Data Redaction
 *
 * @package VirtualizorVpsCleaner\Logging
 * @author  Mudit Kumar Prajapati
 * @license MIT
 */

namespace VirtualizorVpsCleaner\Logging;

use VirtualizorVpsCleaner\Config\VirtualizorConfig;

class Logger
{
    private VirtualizorConfig $config;
    private string $logDir;
    private string $logFile;
    private string $auditJsonFile;

    public function __construct(VirtualizorConfig $config)
    {
        $this->config = $config;
        $this->logDir = $config->getLogDir();
        $this->ensureLogDirectory();
        $this->logFile = $this->logDir . DIRECTORY_SEPARATOR . 'vps-cleaner.log';
        $this->auditJsonFile = $this->logDir . DIRECTORY_SEPARATOR . 'vps-cleaner.audit.jsonl';
    }

    /**
     * Ensure the log directory exists
     */
    private function ensureLogDirectory(): void
    {
        if (!is_dir($this->logDir)) {
            @mkdir($this->logDir, 0750, true);
        }
    }

    /**
     * Log an informational message
     */
    public function info(string $message, array $context = []): void
    {
        $this->log('INFO', $message, $context);
    }

    /**
     * Log a warning message
     */
    public function warning(string $message, array $context = []): void
    {
        $this->log('WARNING', $message, $context);
    }

    /**
     * Log an error message
     */
    public function error(string $message, array $context = []): void
    {
        $this->log('ERROR', $message, $context);
    }

    /**
     * Log a destructive or critical cleanup action
     */
    public function audit(string $action, array $data): void
    {
        $sanitizedData = $this->sanitizeContext($data);
        $timestamp = date('Y-m-d H:i:s');
        $operator = getenv('USER') ?: getenv('USERNAME') ?: 'unknown';

        $entry = array_merge([
            'timestamp' => $timestamp,
            'action'    => $action,
            'operator'  => $operator,
            'hostname'  => gethostname(),
        ], $sanitizedData);

        $jsonLine = json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n";
        @file_put_contents($this->auditJsonFile, $jsonLine, FILE_APPEND | LOCK_EX);

        // Also record in plain text log
        $summary = sprintf(
            "AUDIT [%s] Action: %s by %s - %s",
            $timestamp,
            $action,
            $operator,
            json_encode($sanitizedData, JSON_UNESCAPED_SLASHES)
        );
        @file_put_contents($this->logFile, $summary . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * Internal log writer
     */
    private function log(string $level, string $message, array $context = []): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $sanitizedMsg = $this->redactSensitiveString($message);
        $sanitizedCtx = $this->sanitizeContext($context);

        $ctxString = !empty($sanitizedCtx) ? ' ' . json_encode($sanitizedCtx, JSON_UNESCAPED_SLASHES) : '';
        $line = sprintf("[%s] [%s] %s%s\n", $timestamp, $level, $sanitizedMsg, $ctxString);

        @file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Sanitize array context removing passwords and secrets
     */
    private function sanitizeContext(array $context): array
    {
        $clean = [];
        $sensitiveKeys = ['pass', 'password', 'dbpass', 'token', 'secret', 'key', 'auth'];

        foreach ($context as $key => $value) {
            if (is_array($value)) {
                $clean[$key] = $this->sanitizeContext($value);
            } elseif (is_string($key) && in_array(strtolower($key), $sensitiveKeys, true)) {
                $clean[$key] = '********';
            } elseif (is_string($value)) {
                $clean[$key] = $this->redactSensitiveString($value);
            } else {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }

    /**
     * Redact known database password from string
     */
    private function redactSensitiveString(string $text): string
    {
        $pass = $this->config->getDbPass();
        if (!empty($pass)) {
            $text = str_replace($pass, '********', $text);
        }
        return preg_replace('/password=[^;\s&]+/i', 'password=********', $text) ?? $text;
    }

    /**
     * Read recent audit records from JSON log
     *
     * @param int $limit
     * @return array<array<string, mixed>>
     */
    public function getRecentAuditHistory(int $limit = 20): array
    {
        if (!file_exists($this->auditJsonFile) || !is_readable($this->auditJsonFile)) {
            return [];
        }

        $lines = file($this->auditJsonFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false || empty($lines)) {
            return [];
        }

        $records = [];
        // Read in reverse order (most recent first)
        $lines = array_reverse($lines);
        $count = 0;

        foreach ($lines as $line) {
            if ($count >= $limit) {
                break;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $records[] = $decoded;
                $count++;
            }
        }

        return $records;
    }

    public function getLogFile(): string
    {
        return $this->logFile;
    }

    public function getAuditJsonFile(): string
    {
        return $this->auditJsonFile;
    }
}

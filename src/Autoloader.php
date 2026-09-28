<?php
/**
 * Virtualizor VPS Cleanup Manager
 *
 * Standalone PSR-4 Autoloader
 * Allows the utility to run with zero dependencies on standard Virtualizor PHP
 * (/usr/local/emps/bin/php) without requiring Composer.
 *
 * @package VirtualizorVpsCleaner
 * @author  Mudit Kumar Prajapati
 * @license MIT
 */

namespace VirtualizorVpsCleaner;

class Autoloader
{
    /**
     * Namespace prefix to handle
     *
     * @var string
     */
    private const NAMESPACE_PREFIX = 'VirtualizorVpsCleaner\\';

    /**
     * Base directory for the namespace
     *
     * @var string
     */
    private static string $baseDir = '';

    /**
     * Register the autoloader with SPL
     *
     * @param string|null $baseDir Base directory of src/
     * @return void
     */
    public static function register(?string $baseDir = null): void
    {
        self::$baseDir = $baseDir ?? __DIR__;

        spl_autoload_register([self::class, 'loadClass']);
    }

    /**
     * Loads the class file for a given class name
     *
     * @param string $class Fully qualified class name
     * @return bool True if loaded, false otherwise
     */
    public static function loadClass(string $class): bool
    {
        // Does the class use our namespace prefix?
        $len = strlen(self::NAMESPACE_PREFIX);
        if (strncmp(self::NAMESPACE_PREFIX, $class, $len) !== 0) {
            return false;
        }

        // Get the relative class name
        $relativeClass = substr($class, $len);

        // Replace namespace separators with directory separators and append .php
        $file = self::$baseDir . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass) . '.php';

        if (file_exists($file)) {
            require_once $file;
            return true;
        }

        return false;
    }
}

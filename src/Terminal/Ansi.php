<?php
/**
 * Virtualizor VPS Cleanup Manager
 *
 * Terminal ANSI styling, coloring, and TTY detection
 *
 * @package VirtualizorVpsCleaner\Terminal
 * @author  Mudit Kumar Prajapati
 * @license MIT
 */

namespace VirtualizorVpsCleaner\Terminal;

class Ansi
{
    // Formatting Reset
    public const RESET = "\033[0m";

    // Styles
    public const BOLD       = "\033[1m";
    public const DIM        = "\033[2m";
    public const ITALIC     = "\033[3m";
    public const UNDERLINE  = "\033[4m";
    public const BLINK      = "\033[5m";
    public const INVERT     = "\033[7m";

    // Standard Foreground Colors
    public const BLACK   = "\033[30m";
    public const RED     = "\033[31m";
    public const GREEN   = "\033[32m";
    public const YELLOW  = "\033[33m";
    public const BLUE    = "\033[34m";
    public const MAGENTA = "\033[35m";
    public const CYAN    = "\033[36m";
    public const WHITE   = "\033[37m";

    // High Intensity / Bright Foreground Colors
    public const BRIGHT_BLACK   = "\033[90m"; // Gray
    public const BRIGHT_RED     = "\033[91m";
    public const BRIGHT_GREEN   = "\033[92m";
    public const BRIGHT_YELLOW  = "\033[93m";
    public const BRIGHT_BLUE    = "\033[94m";
    public const BRIGHT_MAGENTA = "\033[95m";
    public const BRIGHT_CYAN    = "\033[96m";
    public const BRIGHT_WHITE   = "\033[97m";

    // Standard Background Colors
    public const BG_BLACK   = "\033[40m";
    public const BG_RED     = "\033[41m";
    public const BG_GREEN   = "\033[42m";
    public const BG_YELLOW  = "\033[43m";
    public const BG_BLUE    = "\033[44m";
    public const BG_MAGENTA = "\033[45m";
    public const BG_CYAN    = "\033[46m";
    public const BG_WHITE   = "\033[47m";

    // High Intensity Background Colors
    public const BG_BRIGHT_BLACK = "\033[100m";
    public const BG_BRIGHT_RED   = "\033[101m";

    /**
     * Whether ANSI color output is enabled
     */
    private static ?bool $enabled = null;

    /**
     * Set ANSI enabled state manually
     */
    public static function setEnabled(bool $enabled): void
    {
        self::$enabled = $enabled;
    }

    /**
     * Check if ANSI colors should be enabled
     */
    public static function isEnabled(): bool
    {
        if (self::$enabled !== null) {
            return self::$enabled;
        }

        // NO_COLOR standard (https://no-color.org/)
        if (getenv('NO_COLOR') !== false) {
            self::$enabled = false;
            return false;
        }

        // Terminal type checks
        $term = getenv('TERM');
        if ($term === 'dumb') {
            self::$enabled = false;
            return false;
        }

        // Windows specific console support
        if (DIRECTORY_SEPARATOR === '\\') {
            // Windows 10 build 10586 or newer supports ANSI VT100
            $hasAnsicon = getenv('ANSICON') !== false;
            $hasConEmu = getenv('ConEmuANSI') === 'ON';
            $isWsl = getenv('WSL_DISTRO_NAME') !== false;
            $isWterm = getenv('WT_SESSION') !== false;

            if ($hasAnsicon || $hasConEmu || $isWsl || $isWterm) {
                self::$enabled = true;
                return true;
            }

            // Check if Windows 10+ VT mode is supported via sapi
            if (function_exists('sapi_windows_vt100_support') && @sapi_windows_vt100_support(STDOUT)) {
                self::$enabled = true;
                return true;
            }

            self::$enabled = true; // Modern Windows Terminal / PowerShell 7 default
            return true;
        }

        // Unix / Linux: Check if output is a TTY
        if (function_exists('posix_isatty')) {
            self::$enabled = @posix_isatty(STDOUT);
            return self::$enabled;
        }

        self::$enabled = true;
        return true;
    }

    /**
     * Format a string with ANSI styling
     *
     * @param string $text Text to format
     * @param string $style ANSI style code(s)
     * @return string
     */
    public static function style(string $text, string $style): string
    {
        if (!self::isEnabled()) {
            return $text;
        }
        return $style . $text . self::RESET;
    }

    // Convenience style helpers
    public static function bold(string $text): string
    {
        return self::style($text, self::BOLD);
    }

    public static function dim(string $text): string
    {
        return self::style($text, self::DIM);
    }

    public static function red(string $text): string
    {
        return self::style($text, self::RED);
    }

    public static function brightRed(string $text): string
    {
        return self::style($text, self::BRIGHT_RED);
    }

    public static function green(string $text): string
    {
        return self::style($text, self::GREEN);
    }

    public static function brightGreen(string $text): string
    {
        return self::style($text, self::BRIGHT_GREEN);
    }

    public static function yellow(string $text): string
    {
        return self::style($text, self::YELLOW);
    }

    public static function brightYellow(string $text): string
    {
        return self::style($text, self::BRIGHT_YELLOW);
    }

    public static function blue(string $text): string
    {
        return self::style($text, self::BLUE);
    }

    public static function cyan(string $text): string
    {
        return self::style($text, self::CYAN);
    }

    public static function brightCyan(string $text): string
    {
        return self::style($text, self::BRIGHT_CYAN);
    }

    public static function magenta(string $text): string
    {
        return self::style($text, self::MAGENTA);
    }

    public static function gray(string $text): string
    {
        return self::style($text, self::BRIGHT_BLACK);
    }

    public static function white(string $text): string
    {
        return self::style($text, self::WHITE);
    }

    public static function brightWhite(string $text): string
    {
        return self::style($text, self::BRIGHT_WHITE);
    }

    public static function success(string $text): string
    {
        return self::style(" ✓ " . $text, self::BRIGHT_GREEN . self::BOLD);
    }

    public static function info(string $text): string
    {
        return self::style(" ℹ " . $text, self::BRIGHT_CYAN);
    }

    public static function warning(string $text): string
    {
        return self::style(" ⚠ " . $text, self::BRIGHT_YELLOW . self::BOLD);
    }

    public static function error(string $text): string
    {
        return self::style(" ✖ " . $text, self::BRIGHT_RED . self::BOLD);
    }

    public static function badge(string $text, string $bgColor = self::BG_BLUE, string $fgColor = self::BRIGHT_WHITE): string
    {
        if (!self::isEnabled()) {
            return "[ " . $text . " ]";
        }
        return $bgColor . $fgColor . self::BOLD . " " . $text . " " . self::RESET;
    }

    /**
     * Strip ANSI codes from a string (useful for calculating true visual length)
     */
    public static function strip(string $text): string
    {
        return preg_replace('/\033\[[0-9;]*m/', '', $text) ?? $text;
    }

    /**
     * Visual string length without ANSI codes
     */
    public static function length(string $text): int
    {
        $plain = self::strip($text);
        return function_exists('mb_strwidth') ? mb_strwidth($plain, 'UTF-8') : strlen($plain);
    }

    /**
     * Clear screen and move cursor to top-left
     */
    public static function clearScreen(): string
    {
        if (!self::isEnabled()) {
            return "\n\n";
        }
        return "\033[2J\033[H";
    }

    /**
     * Horizontal line separator
     */
    public static function hr(int $width = 78, string $char = '─'): string
    {
        return self::gray(str_repeat($char, $width));
    }
}

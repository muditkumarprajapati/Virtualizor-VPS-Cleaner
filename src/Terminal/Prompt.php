<?php
/**
 * Virtualizor VPS Cleanup Manager
 *
 * Terminal User Interaction, Menus and Safety Prompts
 *
 * @package VirtualizorVpsCleaner\Terminal
 * @author  Mudit Kumar Prajapati
 * @license MIT
 */

namespace VirtualizorVpsCleaner\Terminal;

class Prompt
{
    /**
     * Ask a question and return user input
     *
     * @param string $question
     * @param string|null $default
     * @return string
     */
    public static function ask(string $question, ?string $default = null): string
    {
        $suffix = ($default !== null) ? Ansi::dim(" [{$default}]") : '';
        echo Ansi::bold(Ansi::cyan(" ? ")) . Ansi::bold($question) . $suffix . ": ";

        $input = trim((string) fgets(STDIN));
        if ($input === '' && $default !== null) {
            return $default;
        }

        return $input;
    }

    /**
     * Ask a binary yes/no question
     *
     * @param string $question
     * @param bool $default
     * @return bool
     */
    public static function confirm(string $question, bool $default = false): bool
    {
        $options = $default ? "(Y/n)" : "(y/N)";
        echo Ansi::bold(Ansi::yellow(" ? ")) . Ansi::bold($question) . " " . Ansi::dim($options) . ": ";

        $input = strtolower(trim((string) fgets(STDIN)));
        if ($input === '') {
            return $default;
        }

        return in_array($input, ['y', 'yes', '1', 'true'], true);
    }

    /**
     * Strict confirmation requiring the user to type an exact phrase
     * Crucial for Step 5 of the deletion workflow!
     *
     * @param string $promptMessage
     * @param string $expectedPhrase
     * @return bool
     */
    public static function confirmPhrase(string $promptMessage, string $expectedPhrase): bool
    {
        echo "\n" . Ansi::style(" SAFETY CONFIRMATION REQUIRED ", Ansi::BG_RED . Ansi::BRIGHT_WHITE . Ansi::BOLD) . "\n";
        echo Ansi::brightYellow($promptMessage) . "\n";
        echo "To proceed, type exactly: " . Ansi::bold(Ansi::brightRed($expectedPhrase)) . "\n";
        echo "Type anything else or press Enter to cancel immediately.\n\n";

        echo Ansi::bold(Ansi::brightRed(" > "));
        $typed = trim((string) fgets(STDIN));

        return ($typed === $expectedPhrase);
    }

    /**
     * Display an interactive menu and return selected key
     *
     * @param string $title
     * @param array<string|int, string> $options [key => label]
     * @param string|null $default
     * @return string
     */
    public static function menu(string $title, array $options, ?string $default = null): string
    {
        echo "\n" . Ansi::bold(Ansi::brightCyan("─── {$title} ───")) . "\n";

        foreach ($options as $key => $label) {
            $keyFormatted = Ansi::bold(Ansi::cyan("[{$key}]"));
            echo "  {$keyFormatted} {$label}\n";
        }

        echo "\n";
        $choice = self::ask("Select an option", $default);
        return $choice;
    }

    /**
     * Pause execution until Enter is pressed
     */
    public static function pause(string $message = "Press [Enter] to continue..."): void
    {
        echo "\n" . Ansi::dim($message);
        fgets(STDIN);
    }

    /**
     * Display a styled box message (e.g. Danger alert, Warning, Info)
     *
     * @param string $type 'danger'|'warning'|'info'|'success'
     * @param string $title
     * @param array<string> $lines
     * @param int $width
     * @return void
     */
    public static function alert(string $type, string $title, array $lines, int $width = 76): void
    {
        [$color, $borderColor, $badge] = match ($type) {
            'danger'  => [Ansi::BRIGHT_RED, Ansi::RED, "[ DANGER / CAUTION ]"],
            'warning' => [Ansi::BRIGHT_YELLOW, Ansi::YELLOW, "[ WARNING ]"],
            'success' => [Ansi::BRIGHT_GREEN, Ansi::GREEN, "[ SUCCESS ]"],
            default   => [Ansi::BRIGHT_CYAN, Ansi::CYAN, "[ INFORMATION ]"],
        };

        $top = "┌─ " . $badge . " " . str_repeat("─", max(0, $width - Ansi::length($badge) - 5)) . "┐";
        $bottom = "└" . str_repeat("─", $width) . "┘";

        echo "\n" . Ansi::style($top, $borderColor) . "\n";
        echo Ansi::style("│ ", $borderColor) . Ansi::bold(Ansi::style(str_pad($title, $width - 2), $color)) . Ansi::style(" │", $borderColor) . "\n";
        echo Ansi::style("├" . str_repeat("─", $width) . "┤", $borderColor) . "\n";

        foreach ($lines as $line) {
            $plainLen = Ansi::length($line);
            $padding = max(0, $width - $plainLen - 2);
            echo Ansi::style("│ ", $borderColor) . $line . str_repeat(" ", $padding) . Ansi::style(" │", $borderColor) . "\n";
        }

        echo Ansi::style($bottom, $borderColor) . "\n\n";
    }

    /**
     * Display an application header banner
     */
    public static function banner(): void
    {
        echo "\n";
        echo Ansi::style("╔══════════════════════════════════════════════════════════════════════════════╗\n", Ansi::CYAN);
        echo Ansi::style("║", Ansi::CYAN) . Ansi::bold(Ansi::brightWhite(str_pad("VIRTUALIZOR VPS CLEANUP MANAGER", 78, " ", STR_PAD_BOTH))) . Ansi::style("║\n", Ansi::CYAN);
        echo Ansi::style("║", Ansi::CYAN) . Ansi::dim(str_pad("Safe Orphaned Database Record Cleanup for Retired Infrastructure", 78, " ", STR_PAD_BOTH)) . Ansi::style("║\n", Ansi::CYAN);
        echo Ansi::style("╚══════════════════════════════════════════════════════════════════════════════╝\n", Ansi::CYAN);
    }
}

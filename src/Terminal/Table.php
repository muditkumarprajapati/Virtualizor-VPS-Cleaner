<?php
/**
 * Virtualizor VPS Cleanup Manager
 *
 * Terminal Table Formatter
 *
 * @package VirtualizorVpsCleaner\Terminal
 * @author  Mudit Kumar Prajapati
 * @license MIT
 */

namespace VirtualizorVpsCleaner\Terminal;

class Table
{
    /**
     * Table headers
     *
     * @var array<string>
     */
    private array $headers = [];

    /**
     * Table rows
     *
     * @var array<array<string>>
     */
    private array $rows = [];

    /**
     * Max column widths (column index => max characters)
     *
     * @var array<int, int>
     */
    private array $maxColWidths = [];

    /**
     * Alignment per column ('left', 'right', 'center')
     *
     * @var array<int, string>
     */
    private array $alignments = [];

    /**
     * Use unicode box drawing characters
     */
    private bool $useUnicode = true;

    /**
     * Constructor
     *
     * @param array<string> $headers
     * @param array<array<string>> $rows
     */
    public function __construct(array $headers = [], array $rows = [])
    {
        $this->headers = $headers;
        $this->rows = $rows;
    }

    /**
     * Set headers
     *
     * @param array<string> $headers
     * @return self
     */
    public function setHeaders(array $headers): self
    {
        $this->headers = $headers;
        return $this;
    }

    /**
     * Add a single row
     *
     * @param array<string> $row
     * @return self
     */
    public function addRow(array $row): self
    {
        $this->rows[] = $row;
        return $this;
    }

    /**
     * Set rows
     *
     * @param array<array<string>> $rows
     * @return self
     */
    public function setRows(array $rows): self
    {
        $this->rows = $rows;
        return $this;
    }

    /**
     * Set max width for a column
     */
    public function setMaxColWidth(int $colIndex, int $maxWidth): self
    {
        $this->maxColWidths[$colIndex] = $maxWidth;
        return $this;
    }

    /**
     * Set column alignment
     */
    public function setAlignment(int $colIndex, string $align): self
    {
        $this->alignments[$colIndex] = strtolower($align);
        return $this;
    }

    /**
     * Toggle unicode borders
     */
    public function setUseUnicode(bool $use): self
    {
        $this->useUnicode = $use;
        return $this;
    }

    /**
     * Render the table to a string
     */
    public function render(): string
    {
        if (empty($this->headers) && empty($this->rows)) {
            return Ansi::gray(" (No data to display)\n");
        }

        // Determine column count
        $numCols = count($this->headers);
        foreach ($this->rows as $row) {
            $numCols = max($numCols, count($row));
        }

        // Calculate column widths taking ANSI escapes into account
        $colWidths = array_fill(0, $numCols, 0);

        foreach ($this->headers as $i => $h) {
            $len = Ansi::length((string) $h);
            $colWidths[$i] = max($colWidths[$i], $len);
        }

        foreach ($this->rows as $row) {
            foreach ($row as $i => $cell) {
                $len = Ansi::length((string) $cell);
                $colWidths[$i] = max($colWidths[$i], $len);
            }
        }

        // Apply max column constraints
        foreach ($this->maxColWidths as $colIndex => $maxWidth) {
            if (isset($colWidths[$colIndex]) && $colWidths[$colIndex] > $maxWidth) {
                $colWidths[$colIndex] = $maxWidth;
            }
        }

        // Define border characters
        $chars = $this->useUnicode ? [
            'tl' => '┌', 'tm' => '┬', 'tr' => '┐',
            'ml' => '├', 'mm' => '┼', 'mr' => '┤',
            'bl' => '└', 'bm' => '┴', 'br' => '┘',
            'h'  => '─', 'v'  => '│',
        ] : [
            'tl' => '+', 'tm' => '+', 'tr' => '+',
            'ml' => '+', 'mm' => '+', 'mr' => '+',
            'bl' => '+', 'bm' => '+', 'br' => '+',
            'h'  => '-', 'v'  => '|',
        ];

        $output = '';

        // Helper to build horizontal line
        $makeLine = function (string $left, string $mid, string $right) use ($chars, $colWidths) {
            $segments = [];
            foreach ($colWidths as $width) {
                $segments[] = str_repeat($chars['h'], $width + 2);
            }
            return Ansi::gray($left . implode($mid, $segments) . $right . "\n");
        };

        // Top Border
        $output .= $makeLine($chars['tl'], $chars['tm'], $chars['tr']);

        // Headers
        if (!empty($this->headers)) {
            $output .= Ansi::gray($chars['v']);
            foreach ($this->headers as $i => $header) {
                $width = $colWidths[$i] ?? 10;
                $formatted = Ansi::bold(Ansi::brightCyan($this->padCell((string) $header, $width, $this->alignments[$i] ?? 'left')));
                $output .= ' ' . $formatted . ' ' . Ansi::gray($chars['v']);
            }
            $output .= "\n";

            // Header separator line
            $output .= $makeLine($chars['ml'], $chars['mm'], $chars['mr']);
        }

        // Rows
        if (empty($this->rows)) {
            $totalWidth = array_sum($colWidths) + (count($colWidths) * 3) - 1;
            $msg = "No records found";
            $pad = max(0, (int) floor(($totalWidth - strlen($msg)) / 2));
            $output .= Ansi::gray($chars['v']) . str_repeat(' ', $pad) . Ansi::dim($msg) . str_repeat(' ', max(0, $totalWidth - $pad - strlen($msg))) . Ansi::gray($chars['v']) . "\n";
        } else {
            foreach ($this->rows as $rowIndex => $row) {
                $output .= Ansi::gray($chars['v']);
                for ($i = 0; $i < $numCols; $i++) {
                    $cell = $row[$i] ?? '';
                    $width = $colWidths[$i] ?? 10;
                    $output .= ' ' . $this->padCell((string) $cell, $width, $this->alignments[$i] ?? 'left') . ' ' . Ansi::gray($chars['v']);
                }
                $output .= "\n";
            }
        }

        // Bottom Border
        $output .= $makeLine($chars['bl'], $chars['bm'], $chars['br']);

        return $output;
    }

    /**
     * Pad cell content taking ANSI colors into account
     */
    private function padCell(string $text, int $width, string $align = 'left'): string
    {
        $visualLen = Ansi::length($text);

        // Truncate if visual length exceeds column width
        if ($visualLen > $width) {
            $plain = Ansi::strip($text);
            $truncated = mb_substr($plain, 0, max(1, $width - 1)) . '…';
            return $truncated;
        }

        $padNeeded = max(0, $width - $visualLen);

        if ($align === 'right') {
            return str_repeat(' ', $padNeeded) . $text;
        }

        if ($align === 'center') {
            $leftPad = (int) floor($padNeeded / 2);
            $rightPad = $padNeeded - $leftPad;
            return str_repeat(' ', $leftPad) . $text . str_repeat(' ', $rightPad);
        }

        // Default 'left'
        return $text . str_repeat(' ', $padNeeded);
    }
}

<?php

declare(strict_types=1);

namespace PrinterHub\Zpl\Barcode;

use RuntimeException;

/**
 * Code 39 encoder.
 *
 * Each character is nine elements - five bars and four spaces - of which
 * exactly three are wide. Characters are separated by a narrow space, and the
 * whole value is delimited by the start/stop character '*'.
 *
 * Returns run lengths in narrow-module units, so a caller that wants the usual
 * 3:1 wide:narrow ratio multiplies the wide runs itself via $ratio.
 */
final class Code39
{
    /** n = narrow, w = wide; alternating bar,space,... starting with a bar. */
    private const PATTERNS = [
        '0' => 'nnnwwnwnn', '1' => 'wnnwnnnnw', '2' => 'nnwwnnnnw', '3' => 'wnwwnnnnn',
        '4' => 'nnnwwnnnw', '5' => 'wnnwwnnnn', '6' => 'nnwwwnnnn', '7' => 'nnnwnnwnw',
        '8' => 'wnnwnnwnn', '9' => 'nnwwnnwnn', 'A' => 'wnnnnwnnw', 'B' => 'nnwnnwnnw',
        'C' => 'wnwnnwnnn', 'D' => 'nnnnwwnnw', 'E' => 'wnnnwwnnn', 'F' => 'nnwnwwnnn',
        'G' => 'nnnnnwwnw', 'H' => 'wnnnnwwnn', 'I' => 'nnwnnwwnn', 'J' => 'nnnnwwwnn',
        'K' => 'wnnnnnnww', 'L' => 'nnwnnnnww', 'M' => 'wnwnnnnwn', 'N' => 'nnnnwnnww',
        'O' => 'wnnnwnnwn', 'P' => 'nnwnwnnwn', 'Q' => 'nnnnnnwww', 'R' => 'wnnnnnwwn',
        'S' => 'nnwnnnwwn', 'T' => 'nnnnwnwwn', 'U' => 'wwnnnnnnw', 'V' => 'nwwnnnnnw',
        'W' => 'wwwnnnnnn', 'X' => 'nwnnwnnnw', 'Y' => 'wwnnwnnnn', 'Z' => 'nwwnwnnnn',
        '-' => 'nwnnnnwnw', '.' => 'wwnnnnwnn', ' ' => 'nwwnnnwnn', '$' => 'nwnwnwnnn',
        '/' => 'nwnwnnnwn', '+' => 'nwnnnwnwn', '%' => 'nnnwnwnwn', '*' => 'nwnnwnwnn',
    ];

    /**
     * @param int $ratio wide:narrow, 2 or 3 (Zebra's ^BY second parameter)
     *
     * @return list<int> alternating bar,space,... run lengths in narrow units
     */
    public function encode(string $value, int $ratio = 3): array
    {
        if ($value === '') {
            throw new RuntimeException('Code 39 values cannot be empty.');
        }
        if ($ratio < 2 || $ratio > 3) {
            throw new RuntimeException('Code 39 wide:narrow ratio must be 2 or 3.');
        }

        $value = strtoupper($value);
        // '*' delimits the symbol, so it cannot also appear inside it.
        if (str_contains($value, '*')) {
            throw new RuntimeException('Code 39 values cannot contain "*".');
        }

        $runs = [];
        $chars = str_split('*' . $value . '*');
        $last = count($chars) - 1;

        foreach ($chars as $i => $ch) {
            if (!isset(self::PATTERNS[$ch])) {
                throw new RuntimeException(sprintf('Code 39 cannot encode "%s".', $ch));
            }
            foreach (str_split(self::PATTERNS[$ch]) as $el) {
                $runs[] = $el === 'w' ? $ratio : 1;
            }
            // Inter-character gap: one narrow space, except after the last char.
            if ($i !== $last) {
                $runs[] = 1;
            }
        }

        return $runs;
    }

    public function moduleWidth(string $value, int $ratio = 3): int
    {
        return array_sum($this->encode($value, $ratio));
    }
}

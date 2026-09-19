<?php

declare(strict_types=1);

namespace PrinterHub\Zpl\Barcode;

use RuntimeException;

/**
 * QR Code encoder (byte mode, versions 1-10, EC levels L/M/Q/H).
 *
 * Pure PHP on purpose. The preview re-renders on every keystroke, so shelling
 * out to qrencode per render would be wasteful, and more importantly the
 * preview is meant to work anywhere PHP does. The tables here are verified
 * against qrencode as an oracle - see QrCodeTest - because a QR code that is
 * subtly wrong still renders beautifully and scans as garbage, which is a far
 * worse failure than not rendering at all.
 *
 * Byte mode only: it encodes any payload, and the density gain from numeric or
 * alphanumeric mode does not justify three more code paths that could be wrong.
 */
final class QrCode
{
    public const EC_L = 0;
    public const EC_M = 1;
    public const EC_Q = 2;
    public const EC_H = 3;

    /** Bit patterns for the EC level as written into the format information. */
    private const EC_FORMAT_BITS = [self::EC_L => 0b01, self::EC_M => 0b00, self::EC_Q => 0b11, self::EC_H => 0b10];

    /**
     * Per version: total codewords, then per EC level
     * [ec codewords per block, blocks in group 1, data codewords in a group-1
     * block, blocks in group 2, data codewords in a group-2 block].
     *
     * @var array<int,array{total:int,ec:array<int,array{int,int,int,int,int}>}>
     */
    private const VERSIONS = [
        1 => ['total' => 26, 'ec' => [
            self::EC_L => [7, 1, 19, 0, 0], self::EC_M => [10, 1, 16, 0, 0],
            self::EC_Q => [13, 1, 13, 0, 0], self::EC_H => [17, 1, 9, 0, 0]]],
        2 => ['total' => 44, 'ec' => [
            self::EC_L => [10, 1, 34, 0, 0], self::EC_M => [16, 1, 28, 0, 0],
            self::EC_Q => [22, 1, 22, 0, 0], self::EC_H => [28, 1, 16, 0, 0]]],
        3 => ['total' => 70, 'ec' => [
            self::EC_L => [15, 1, 55, 0, 0], self::EC_M => [26, 1, 44, 0, 0],
            self::EC_Q => [18, 2, 17, 0, 0], self::EC_H => [22, 2, 13, 0, 0]]],
        4 => ['total' => 100, 'ec' => [
            self::EC_L => [20, 1, 80, 0, 0], self::EC_M => [18, 2, 32, 0, 0],
            self::EC_Q => [26, 2, 24, 0, 0], self::EC_H => [16, 4, 9, 0, 0]]],
        5 => ['total' => 134, 'ec' => [
            self::EC_L => [26, 1, 108, 0, 0], self::EC_M => [24, 2, 43, 0, 0],
            self::EC_Q => [18, 2, 15, 2, 16], self::EC_H => [22, 2, 11, 2, 12]]],
        6 => ['total' => 172, 'ec' => [
            self::EC_L => [18, 2, 68, 0, 0], self::EC_M => [16, 4, 27, 0, 0],
            self::EC_Q => [24, 4, 19, 0, 0], self::EC_H => [28, 4, 15, 0, 0]]],
        7 => ['total' => 196, 'ec' => [
            self::EC_L => [20, 2, 78, 0, 0], self::EC_M => [18, 4, 31, 0, 0],
            self::EC_Q => [18, 2, 14, 4, 15], self::EC_H => [26, 4, 13, 1, 14]]],
        8 => ['total' => 242, 'ec' => [
            self::EC_L => [24, 2, 97, 0, 0], self::EC_M => [22, 2, 38, 2, 39],
            self::EC_Q => [22, 4, 18, 2, 19], self::EC_H => [26, 4, 14, 2, 15]]],
        9 => ['total' => 292, 'ec' => [
            self::EC_L => [30, 2, 116, 0, 0], self::EC_M => [22, 3, 36, 2, 37],
            self::EC_Q => [20, 4, 16, 4, 17], self::EC_H => [24, 4, 12, 4, 13]]],
        10 => ['total' => 346, 'ec' => [
            self::EC_L => [18, 2, 68, 2, 69], self::EC_M => [26, 4, 43, 1, 44],
            self::EC_Q => [24, 6, 19, 2, 20], self::EC_H => [28, 6, 15, 2, 16]]],
    ];

    /** Centre coordinates of alignment patterns, per version. */
    private const ALIGNMENT = [
        1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30],
        6 => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50],
    ];

    /** @var array<int,int> */
    private static array $expTable = [];
    /** @var array<int,int> */
    private static array $logTable = [];

    /**
     * Encode to a square matrix of booleans (true = dark module).
     *
     * @return list<list<bool>>
     */
    public function encode(string $data, int $ec = self::EC_M, ?int $forceMask = null): array
    {
        if ($data === '') {
            throw new RuntimeException('QR values cannot be empty.');
        }
        if (!isset(self::EC_FORMAT_BITS[$ec])) {
            throw new RuntimeException('QR error correction level must be L, M, Q or H.');
        }

        $version = $this->chooseVersion(strlen($data), $ec);
        [$ecPerBlock, $g1Blocks, $g1Words, $g2Blocks, $g2Words] = self::VERSIONS[$version]['ec'][$ec];
        $dataCapacity = $g1Blocks * $g1Words + $g2Blocks * $g2Words;

        $bits = $this->buildBitStream($data, $version, $dataCapacity);
        $codewords = $this->interleave($bits, $ecPerBlock, $g1Blocks, $g1Words, $g2Blocks, $g2Words);

        $size = 17 + 4 * $version;
        $reserved = $this->reservedMap($version, $size);
        $matrix = $this->placeFunctionPatterns($version, $size);
        $this->placeData($matrix, $reserved, $codewords, $size);

        // Try all eight masks and keep the least penalised, as the spec requires.
        // $forceMask exists so tests can compare a specific mask against an
        // external encoder: two implementations can both be correct and still
        // choose different masks, which would make a raw matrix diff useless.
        $best = null;
        $bestScore = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) {
            if ($forceMask !== null && $mask !== $forceMask) {
                continue;
            }
            $candidate = $matrix;
            $this->applyMask($candidate, $reserved, $mask, $size);
            $this->writeFormatInfo($candidate, $ec, $mask, $size);
            if ($version >= 7) {
                $this->writeVersionInfo($candidate, $version, $size);
            }
            $score = $this->penalty($candidate, $size);
            if ($score < $bestScore) {
                $bestScore = $score;
                $best = $candidate;
            }
        }

        /** @var list<list<bool>> $best */
        return $best;
    }

    private function chooseVersion(int $length, int $ec): int
    {
        foreach (self::VERSIONS as $version => $spec) {
            [, $g1Blocks, $g1Words, $g2Blocks, $g2Words] = $spec['ec'][$ec];
            $capacityBits = ($g1Blocks * $g1Words + $g2Blocks * $g2Words) * 8;
            // 4 bits mode indicator + the length field for this version.
            $needed = 4 + ($version < 10 ? 8 : 16) + $length * 8;
            if ($needed <= $capacityBits) {
                return $version;
            }
        }

        throw new RuntimeException(sprintf(
            'QR payload of %d bytes exceeds version 10 at this error-correction level.',
            $length
        ));
    }

    /** @return list<int> data codewords, padded to capacity */
    private function buildBitStream(string $data, int $version, int $dataCapacity): array
    {
        $bits = '';
        $bits .= '0100';                                     // byte mode
        $lengthBits = $version < 10 ? 8 : 16;
        $bits .= str_pad(decbin(strlen($data)), $lengthBits, '0', STR_PAD_LEFT);
        for ($i = 0, $n = strlen($data); $i < $n; $i++) {
            $bits .= str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
        }

        $capacityBits = $dataCapacity * 8;
        // Terminator: up to four zero bits, truncated if the stream is nearly full.
        $bits .= str_repeat('0', min(4, $capacityBits - strlen($bits)));
        // Pad to a byte boundary.
        if (strlen($bits) % 8 !== 0) {
            $bits .= str_repeat('0', 8 - (strlen($bits) % 8));
        }

        $codewords = [];
        foreach (str_split($bits, 8) as $byte) {
            $codewords[] = bindec($byte);
        }
        // Alternating pad codewords until the data capacity is filled.
        $pad = [0xEC, 0x11];
        $i = 0;
        while (count($codewords) < $dataCapacity) {
            $codewords[] = $pad[$i % 2];
            $i++;
        }

        return $codewords;
    }

    /**
     * Split into blocks, compute EC per block, then interleave both, which is
     * what makes a QR code resilient to a contiguous smudge rather than just to
     * scattered noise.
     *
     * @param list<int> $data
     *
     * @return list<int>
     */
    private function interleave(array $data, int $ecPerBlock, int $g1Blocks, int $g1Words, int $g2Blocks, int $g2Words): array
    {
        $blocks = [];
        $ecBlocks = [];
        $offset = 0;

        foreach ([[$g1Blocks, $g1Words], [$g2Blocks, $g2Words]] as [$count, $words]) {
            for ($b = 0; $b < $count; $b++) {
                $block = array_slice($data, $offset, $words);
                $offset += $words;
                $blocks[] = $block;
                $ecBlocks[] = $this->reedSolomon($block, $ecPerBlock);
            }
        }

        $out = [];
        $maxWords = max($g1Words, $g2Words);
        for ($i = 0; $i < $maxWords; $i++) {
            foreach ($blocks as $block) {
                if (isset($block[$i])) {
                    $out[] = $block[$i];
                }
            }
        }
        for ($i = 0; $i < $ecPerBlock; $i++) {
            foreach ($ecBlocks as $block) {
                $out[] = $block[$i];
            }
        }

        return $out;
    }

    /**
     * @param list<int> $data
     *
     * @return list<int>
     */
    private function reedSolomon(array $data, int $ecLength): array
    {
        $this->initGalois();
        $generator = $this->generatorPolynomial($ecLength);
        $remainder = array_merge($data, array_fill(0, $ecLength, 0));

        for ($i = 0, $n = count($data); $i < $n; $i++) {
            $factor = $remainder[$i];
            if ($factor === 0) {
                continue;
            }
            $logFactor = self::$logTable[$factor];
            foreach ($generator as $j => $g) {
                $remainder[$i + $j] ^= self::$expTable[($logFactor + $g) % 255];
            }
        }

        return array_slice($remainder, count($data));
    }

    /** @return list<int> generator polynomial coefficients as GF logs */
    private function generatorPolynomial(int $degree): array
    {
        $poly = [0];                                          // x^0, log form
        for ($d = 0; $d < $degree; $d++) {
            $next = array_fill(0, count($poly) + 1, 0);
            foreach ($poly as $i => $coeff) {
                // Multiply by (x + a^d), coefficients stored highest-degree
                // first. Multiplying by x keeps the index (the list grows at
                // the tail); scaling by a^d moves one index DOWN in degree.
                // Swapping these two yields the reversed polynomial, which is
                // still a plausible-looking generator and produces check bytes
                // that are entirely wrong.
                $next[$i] ^= self::$expTable[$coeff];
                $next[$i + 1] ^= self::$expTable[($coeff + $d) % 255];
            }
            $poly = [];
            foreach ($next as $v) {
                $poly[] = self::$logTable[$v];
            }
        }

        return $poly;
    }

    private function initGalois(): void
    {
        if (self::$expTable !== []) {
            return;
        }
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            self::$expTable[$i] = $x;
            self::$logTable[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= 0x11D;                                  // QR's primitive polynomial
            }
        }
    }

    /** @return list<list<bool>> */
    private function placeFunctionPatterns(int $version, int $size): array
    {
        $m = array_fill(0, $size, array_fill(0, $size, false));

        $finder = static function (array &$m, int $r, int $c) use ($size): void {
            for ($dr = -1; $dr <= 7; $dr++) {
                for ($dc = -1; $dc <= 7; $dc++) {
                    $rr = $r + $dr;
                    $cc = $c + $dc;
                    if ($rr < 0 || $rr >= $size || $cc < 0 || $cc >= $size) {
                        continue;
                    }
                    $inRing = ($dr >= 0 && $dr <= 6 && $dc >= 0 && $dc <= 6)
                        && ($dr === 0 || $dr === 6 || $dc === 0 || $dc === 6
                            || ($dr >= 2 && $dr <= 4 && $dc >= 2 && $dc <= 4));
                    $m[$rr][$cc] = $inRing;
                }
            }
        };
        $finder($m, 0, 0);
        $finder($m, 0, $size - 7);
        $finder($m, $size - 7, 0);

        // Timing patterns.
        for ($i = 8; $i < $size - 8; $i++) {
            $m[6][$i] = $i % 2 === 0;
            $m[$i][6] = $i % 2 === 0;
        }

        // Alignment patterns, skipping those that would collide with a finder.
        $centres = self::ALIGNMENT[$version];
        foreach ($centres as $r) {
            foreach ($centres as $c) {
                if (($r <= 8 && $c <= 8) || ($r <= 8 && $c >= $size - 9) || ($r >= $size - 9 && $c <= 8)) {
                    continue;
                }
                for ($dr = -2; $dr <= 2; $dr++) {
                    for ($dc = -2; $dc <= 2; $dc++) {
                        $m[$r + $dr][$c + $dc] = max(abs($dr), abs($dc)) !== 1;
                    }
                }
            }
        }

        // The dark module, always set.
        $m[$size - 8][8] = true;

        return $m;
    }

    /** @return list<list<bool>> true where a module is reserved for function patterns */
    private function reservedMap(int $version, int $size): array
    {
        $r = array_fill(0, $size, array_fill(0, $size, false));

        $block = static function (array &$r, int $r0, int $c0, int $h, int $w) use ($size): void {
            for ($i = 0; $i < $h; $i++) {
                for ($j = 0; $j < $w; $j++) {
                    if ($r0 + $i < $size && $c0 + $j < $size) {
                        $r[$r0 + $i][$c0 + $j] = true;
                    }
                }
            }
        };

        // Finders plus their separators and the adjacent format information.
        $block($r, 0, 0, 9, 9);
        $block($r, 0, $size - 8, 9, 8);
        $block($r, $size - 8, 0, 8, 9);

        // Timing patterns.
        for ($i = 0; $i < $size; $i++) {
            $r[6][$i] = true;
            $r[$i][6] = true;
        }

        // Alignment patterns.
        $centres = self::ALIGNMENT[$version];
        foreach ($centres as $cr) {
            foreach ($centres as $cc) {
                if (($cr <= 8 && $cc <= 8) || ($cr <= 8 && $cc >= $size - 9) || ($cr >= $size - 9 && $cc <= 8)) {
                    continue;
                }
                $block($r, $cr - 2, $cc - 2, 5, 5);
            }
        }

        // Version information blocks, present from version 7.
        if ($version >= 7) {
            $block($r, 0, $size - 11, 6, 3);
            $block($r, $size - 11, 0, 3, 6);
        }

        return $r;
    }

    /**
     * @param list<list<bool>> $matrix
     * @param list<list<bool>> $reserved
     * @param list<int>        $codewords
     */
    private function placeData(array &$matrix, array $reserved, array $codewords, int $size): void
    {
        $bits = '';
        foreach ($codewords as $cw) {
            $bits .= str_pad(decbin($cw), 8, '0', STR_PAD_LEFT);
        }

        $index = 0;
        $upward = true;
        // Two-module-wide columns, right to left, skipping the timing column.
        for ($right = $size - 1; $right > 0; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }
            for ($v = 0; $v < $size; $v++) {
                $row = $upward ? $size - 1 - $v : $v;
                for ($c = 0; $c < 2; $c++) {
                    $col = $right - $c;
                    if ($reserved[$row][$col]) {
                        continue;
                    }
                    $matrix[$row][$col] = isset($bits[$index]) && $bits[$index] === '1';
                    $index++;
                }
            }
            $upward = !$upward;
        }
    }

    /**
     * @param list<list<bool>> $matrix
     * @param list<list<bool>> $reserved
     */
    private function applyMask(array &$matrix, array $reserved, int $mask, int $size): void
    {
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c < $size; $c++) {
                if ($reserved[$r][$c]) {
                    continue;
                }
                $flip = match ($mask) {
                    0 => ($r + $c) % 2 === 0,
                    1 => $r % 2 === 0,
                    2 => $c % 3 === 0,
                    3 => ($r + $c) % 3 === 0,
                    4 => (intdiv($r, 2) + intdiv($c, 3)) % 2 === 0,
                    5 => (($r * $c) % 2) + (($r * $c) % 3) === 0,
                    6 => ((($r * $c) % 2) + (($r * $c) % 3)) % 2 === 0,
                    7 => ((($r + $c) % 2) + (($r * $c) % 3)) % 2 === 0,
                };
                if ($flip) {
                    $matrix[$r][$c] = !$matrix[$r][$c];
                }
            }
        }
    }

    /** @param list<list<bool>> $matrix */
    private function writeFormatInfo(array &$matrix, int $ec, int $mask, int $size): void
    {
        $data = (self::EC_FORMAT_BITS[$ec] << 3) | $mask;
        $rem = $data;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ ((($rem >> 9) & 1) * 0x537);
        }
        $bits = (($data << 10) | $rem) ^ 0x5412;

        // Copy 1 runs DOWN column 8 (rows 0-8), then LEFT along row 8.
        // Copy 2 runs LEFT along row 8 from the right edge, then UP column 8
        // from the bottom. Getting these two axes the wrong way round produces
        // a matrix that still looks like a valid QR code and scans as nothing,
        // which is exactly why this is verified against qrencode.
        for ($i = 0; $i < 15; $i++) {
            $bit = (($bits >> $i) & 1) === 1;

            // --- copy 1, around the top-left finder ---
            if ($i < 6) {
                $matrix[$i][8] = $bit;
            } elseif ($i === 6) {
                $matrix[7][8] = $bit;
            } elseif ($i === 7) {
                $matrix[8][8] = $bit;
            } elseif ($i === 8) {
                $matrix[8][7] = $bit;
            } else {
                $matrix[8][14 - $i] = $bit;
            }

            // --- copy 2, split between the other two finders ---
            if ($i < 8) {
                $matrix[8][$size - 1 - $i] = $bit;
            } else {
                $matrix[$size - 15 + $i][8] = $bit;
            }
        }
    }

    /** @param list<list<bool>> $matrix */
    private function writeVersionInfo(array &$matrix, int $version, int $size): void
    {
        $rem = $version;
        for ($i = 0; $i < 12; $i++) {
            $rem = ($rem << 1) ^ ((($rem >> 11) & 1) * 0x1F25);
        }
        $bits = ($version << 12) | $rem;

        for ($i = 0; $i < 18; $i++) {
            $bit = (($bits >> $i) & 1) === 1;
            $r = intdiv($i, 3);
            $c = $i % 3;
            $matrix[$r][$size - 11 + $c] = $bit;
            $matrix[$size - 11 + $c][$r] = $bit;
        }
    }

    /** @param list<list<bool>> $m */
    private function penalty(array $m, int $size): int
    {
        $score = 0;

        // Rule 1: runs of five or more same-coloured modules.
        foreach ([true, false] as $transpose) {
            for ($a = 0; $a < $size; $a++) {
                $run = 1;
                for ($b = 1; $b < $size; $b++) {
                    $cur = $transpose ? $m[$b][$a] : $m[$a][$b];
                    $prev = $transpose ? $m[$b - 1][$a] : $m[$a][$b - 1];
                    if ($cur === $prev) {
                        $run++;
                        continue;
                    }
                    if ($run >= 5) {
                        $score += 3 + ($run - 5);
                    }
                    $run = 1;
                }
                if ($run >= 5) {
                    $score += 3 + ($run - 5);
                }
            }
        }

        // Rule 2: 2x2 blocks of one colour.
        for ($r = 0; $r < $size - 1; $r++) {
            for ($c = 0; $c < $size - 1; $c++) {
                if ($m[$r][$c] === $m[$r][$c + 1]
                    && $m[$r][$c] === $m[$r + 1][$c]
                    && $m[$r][$c] === $m[$r + 1][$c + 1]) {
                    $score += 3;
                }
            }
        }

        // Rule 3: finder-like 1:1:3:1:1 patterns with four light modules beside.
        $p1 = [true, false, true, true, true, false, true, false, false, false, false];
        $p2 = [false, false, false, false, true, false, true, true, true, false, true];
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c <= $size - 11; $c++) {
                $h1 = true;
                $h2 = true;
                $v1 = true;
                $v2 = true;
                for ($k = 0; $k < 11; $k++) {
                    $h1 = $h1 && $m[$r][$c + $k] === $p1[$k];
                    $h2 = $h2 && $m[$r][$c + $k] === $p2[$k];
                    $v1 = $v1 && $m[$c + $k][$r] === $p1[$k];
                    $v2 = $v2 && $m[$c + $k][$r] === $p2[$k];
                }
                $score += (int) $h1 * 40 + (int) $h2 * 40 + (int) $v1 * 40 + (int) $v2 * 40;
            }
        }

        // Rule 4: deviation from an even split of dark and light.
        $dark = 0;
        foreach ($m as $row) {
            foreach ($row as $cell) {
                if ($cell) {
                    $dark++;
                }
            }
        }
        $percent = ($dark * 100) / ($size * $size);
        $score += (int) (abs($percent - 50) / 5) * 10;

        return $score;
    }
}

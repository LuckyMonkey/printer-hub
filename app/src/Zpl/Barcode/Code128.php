<?php

declare(strict_types=1);

namespace PrinterHub\Zpl\Barcode;

use RuntimeException;

/**
 * Code 128 encoder.
 *
 * Returns the bar/space run lengths for a value, in modules, always starting
 * with a bar. The caller decides how wide a module is, which is what lets the
 * same encoding serve both a 203dpi preview and a 300dpi printer.
 *
 * Subsets B and C are both used: C packs two digits into one symbol, so a long
 * numeric value encodes to roughly half the width. Zebra's ^BC does the same
 * thing in auto mode, and a preview that did not would render barcodes visibly
 * wider than the printer produces.
 */
final class Code128
{
    /**
     * Run lengths per symbol value. Index 106 is the stop pattern, which is 13
     * modules rather than 11 because it carries an extra terminating bar.
     *
     * @var list<string>
     */
    private const PATTERNS = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
    ];

    private const START_B = 104;
    private const START_C = 105;
    private const CODE_B = 100;
    private const CODE_C = 99;
    private const STOP = 106;

    /**
     * @return list<int> alternating bar,space,... run lengths in modules
     */
    public function encode(string $value): array
    {
        if ($value === '') {
            throw new RuntimeException('Code 128 values cannot be empty.');
        }
        if (preg_match('/[^\x20-\x7E]/', $value) === 1) {
            throw new RuntimeException('Code 128 supports printable ASCII (32..126).');
        }

        $codes = $this->toCodes($value);

        // Checksum: start value plus each payload value weighted by position.
        $sum = $codes[0];
        for ($i = 1; $i < count($codes); $i++) {
            $sum += $codes[$i] * $i;
        }
        $codes[] = $sum % 103;
        $codes[] = self::STOP;

        $runs = [];
        foreach ($codes as $code) {
            foreach (str_split(self::PATTERNS[$code]) as $w) {
                $runs[] = (int) $w;
            }
        }

        return $runs;
    }

    /** Total width in modules, useful for fitting a barcode to a field. */
    public function moduleWidth(string $value): int
    {
        return array_sum($this->encode($value));
    }

    /**
     * Choose subsets. Subset C is worth switching into only for a run of at
     * least four digits: the switch itself costs a symbol, so shorter runs come
     * out the same size or larger.
     *
     * @return list<int>
     */
    private function toCodes(string $value): array
    {
        $len = strlen($value);
        $startC = $this->digitRunAt($value, 0) >= ($len === 2 ? 2 : 4);

        $codes = [$startC ? self::START_C : self::START_B];
        $mode = $startC ? 'C' : 'B';
        $i = 0;

        while ($i < $len) {
            if ($mode === 'C') {
                $run = $this->digitRunAt($value, $i);
                if ($run >= 2) {
                    $pairs = intdiv($run, 2);
                    for ($p = 0; $p < $pairs; $p++) {
                        $codes[] = (int) substr($value, $i, 2);
                        $i += 2;
                    }
                    continue;
                }
                $codes[] = self::CODE_B;
                $mode = 'B';
                continue;
            }

            $run = $this->digitRunAt($value, $i);
            // An even run of 4+ (or a run that ends the value) pays for itself.
            if ($run >= 4 && $run % 2 === 0) {
                $codes[] = self::CODE_C;
                $mode = 'C';
                continue;
            }

            $codes[] = ord($value[$i]) - 32;
            $i++;
        }

        return $codes;
    }

    private function digitRunAt(string $value, int $offset): int
    {
        $n = 0;
        $len = strlen($value);
        for ($i = $offset; $i < $len && ctype_digit($value[$i]); $i++) {
            $n++;
        }

        return $n;
    }
}

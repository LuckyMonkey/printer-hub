<?php
declare(strict_types=1);

namespace PrinterHub;

use RuntimeException;

final class ZebraLabelService
{
    /**
     * @param list<string> $values
     */
    public function buildBatchGridZpl(string $labelType, string $barcodeType, array $values): string
    {
        $barcodeType = strtoupper(trim($barcodeType));
        $labelType = strtolower(trim($labelType));
        $count = count($values);

        if ($count < 1 || $count > 12) {
            throw new RuntimeException('Zebra batch grid requires 1 to 12 values per label.');
        }

        $title = $labelType === 'status-tag' ? 'Status Tag Batch' : 'Waco ID Batch';

        $xPositions = [24, 414];
        $yStart = 38;
        $rowStep = 194;
        $cellWidth = 374;
        $cellHeight = 178;

        $lines = [
            '^XA',
            '^CI28',
            '^PW812',
            '^LL1218',
            '^LH0,0',
            sprintf('^FO24,8^A0N,24,22^FD%s (%d)^FS', $title, $count),
        ];

        foreach ($values as $index => $rawValue) {
            $safeValue = $this->sanitize($rawValue);
            if ($safeValue === '') {
                continue;
            }

            if ($barcodeType === 'UPCA') {
                $safeValue = $this->normalizeUpca($safeValue);
            }

            $row = intdiv($index, 2);
            $col = $index % 2;

            $x = $xPositions[$col];
            $y = $yStart + ($row * $rowStep);

            $lines[] = sprintf('^FO%d,%d^GB%d,%d,1^FS', $x, $y, $cellWidth, $cellHeight);
            $lines[] = sprintf('^FO%d,%d^A0N,20,18^FD#%d^FS', $x + 8, $y + 6, $index + 1);

            if ($barcodeType === 'QR') {
                $lines[] = sprintf('^FO%d,%d^BQN,2,3^FDLA,%s^FS', $x + 12, $y + 26, $safeValue);
                $lines[] = sprintf('^FO%d,%d^A0N,18,16^FD%s^FS', $x + 142, $y + 84, $safeValue);
                continue;
            }

            if ($barcodeType === 'UPCA') {
                $lines[] = '^BY2,2,64';
                $lines[] = sprintf('^FO%d,%d^BUN,64,N,N^FD%s^FS', $x + 12, $y + 28, $safeValue);
                $lines[] = sprintf('^FO%d,%d^A0N,20,18^FD%s^FS', $x + 12, $y + 118, $safeValue);
                continue;
            }

            $lines[] = '^BY2,2,64';
            $lines[] = sprintf('^FO%d,%d^BCN,64,N,N,N^FD%s^FS', $x + 12, $y + 28, $safeValue);
            $lines[] = sprintf('^FO%d,%d^A0N,20,18^FD%s^FS', $x + 12, $y + 118, $safeValue);
        }

        $lines[] = '^XZ';

        return implode("\n", $lines) . "\n";
    }

    public function buildZpl(string $labelType, string $barcodeType, string $barcodeValue, ?string $textLine1): string
    {
        $barcodeType = strtoupper(trim($barcodeType));
        $labelType = strtolower(trim($labelType));

        $safeValue = $this->sanitize($barcodeValue);
        if ($safeValue === '') {
            throw new RuntimeException('barcodeValue is required for Zebra printing.');
        }

        $safeText = $this->sanitize($textLine1 ?? '');

        if ($barcodeType === 'UPCA') {
            $safeValue = $this->normalizeUpca($safeValue);
        }

        if ($labelType === 'status-tag') {
            return $this->buildStatusTag($barcodeType, $safeValue, $safeText);
        }

        return $this->buildWacoId($barcodeType, $safeValue, $safeText);
    }

    private function buildWacoId(string $barcodeType, string $barcodeValue, string $textLine1): string
    {
        $lines = [
            '^XA',
            '^CI28',
            '^PW812',
            '^LL420',
            '^LH0,0',
            '^FO40,28^A0N,36,34^FDWaco ID^FS',
        ];

        $lines = array_merge($lines, $this->barcodeBlock($barcodeType, $barcodeValue, 40, 90));

        if ($textLine1 !== '') {
            $lines[] = sprintf('^FO40,325^A0N,34,30^FD%s^FS', $textLine1);
        }

        $lines[] = '^XZ';

        return implode("\n", $lines) . "\n";
    }

    private function buildStatusTag(string $barcodeType, string $barcodeValue, string $textLine1): string
    {
        $lines = [
            '^XA',
            '^CI28',
            '^PW812',
            '^LL320',
            '^LH0,0',
            '^FO34,24^A0N,30,28^FDStatus Tag^FS',
        ];

        $lines = array_merge($lines, $this->barcodeBlock($barcodeType, $barcodeValue, 36, 74));

        if ($textLine1 !== '') {
            $lines[] = sprintf('^FO36,258^A0N,28,26^FD%s^FS', $textLine1);
        }

        $lines[] = '^XZ';

        return implode("\n", $lines) . "\n";
    }

    /** @return list<string> */
    private function barcodeBlock(string $barcodeType, string $barcodeValue, int $x, int $y): array
    {
        if ($barcodeType === 'QR') {
            return [
                sprintf('^FO%d,%d^BQN,2,6^FDLA,%s^FS', $x, $y, $barcodeValue),
                sprintf('^FO%d,%d^A0N,26,24^FD%s^FS', $x + 190, $y + 66, $barcodeValue),
            ];
        }

        if ($barcodeType === 'UPCA') {
            return [
                '^BY2,2,120',
                sprintf('^FO%d,%d^BUN,120,Y,N^FD%s^FS', $x, $y, $barcodeValue),
            ];
        }

        return [
            '^BY2,2,120',
            sprintf('^FO%d,%d^BCN,120,Y,N,N^FD%s^FS', $x, $y, $barcodeValue),
        ];
    }

    private function sanitize(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/[\x00-\x1F\x7F]/', '', $value) ?? '';
        $value = str_replace(['^', '~'], ' ', $value);

        return $value;
    }

    private function normalizeUpca(string $value): string
    {
        if (!preg_match('/^\d{11,12}$/', $value)) {
            throw new RuntimeException('UPCA requires 11 or 12 digits.');
        }

        if (strlen($value) === 12) {
            return $value;
        }

        $odd = 0;
        $even = 0;
        for ($i = 0; $i < 11; $i++) {
            $digit = (int) $value[$i];
            if ($i % 2 === 0) {
                $odd += $digit;
            } else {
                $even += $digit;
            }
        }

        $sum = ($odd * 3) + $even;
        $checkDigit = (10 - ($sum % 10)) % 10;

        return $value . (string) $checkDigit;
    }
}

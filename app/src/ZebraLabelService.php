<?php
declare(strict_types=1);

namespace PrinterHub;

use RuntimeException;

final class ZebraLabelService
{
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

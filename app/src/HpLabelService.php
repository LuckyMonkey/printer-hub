<?php
declare(strict_types=1);

namespace PrinterHub;

final class HpLabelService
{
    public function buildTextDocument(
        string $labelType,
        string $barcodeType,
        string $barcodeValue,
        ?string $textLine1,
        int $copies
    ): string {
        $title = $labelType === 'status-tag' ? 'Status Tag' : 'Waco ID';
        $text = trim((string) $textLine1);

        $lines = [
            'Printer Hub - HP Envy 5055',
            '============================',
            'Label Type: ' . $title,
            'Barcode Type: ' . strtoupper($barcodeType),
            'Barcode Value: ' . $barcodeValue,
            'Text Line 1: ' . ($text !== '' ? $text : '(empty)'),
            'Copies Requested: ' . $copies,
            'Printed At: ' . gmdate('Y-m-d H:i:s') . ' UTC',
        ];

        return implode("\n", $lines) . "\n";
    }
}

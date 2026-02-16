<?php
declare(strict_types=1);

namespace PrinterHub;

use RuntimeException;

/**
 * P-touch Template command stream builder.
 *
 * Commands implemented from the Brother P-touch Template command reference:
 * - ESC i a n : command mode select (n=0x03 => template mode)
 * - ^II       : initialize dynamic command mode state
 * - ^TSxxx    : select template number
 * - ^OSxx     : select object by index
 * - ^DI..     : insert object data (length lo/hi + bytes)
 * - ^CNxxx    : set copies
 * - ^FF       : print
 */
final class BrotherTemplateClient
{
    public const TEMPLATE_MODE_BYTE = "\x03";

    /**
     * @param array<int,string> $objectDataByIndex
     */
    public function buildCommandStream(int $templateId, array $objectDataByIndex, int $copies = 1): string
    {
        if ($templateId < 1 || $templateId > 999) {
            throw new RuntimeException('Brother templateId must be between 1 and 999.');
        }

        if ($copies < 1 || $copies > 999) {
            throw new RuntimeException('Brother copies must be between 1 and 999.');
        }

        $stream = '';

        // Reset printer and enter P-touch Template mode.
        $stream .= "\x1B\x40";
        $stream .= "\x1B\x69\x61" . self::TEMPLATE_MODE_BYTE;

        // Initialize dynamic command state and select template/copies.
        $stream .= '^II';
        $stream .= sprintf('^TS%03d', $templateId);
        $stream .= sprintf('^CN%03d', $copies);

        ksort($objectDataByIndex);
        foreach ($objectDataByIndex as $index => $value) {
            if ($index < 1 || $index > 99) {
                throw new RuntimeException('Brother object index must be between 1 and 99.');
            }

            $payload = $this->sanitizeData($value);
            $length = strlen($payload);
            if ($length > 65535) {
                throw new RuntimeException('Brother object payload exceeds maximum length (65535 bytes).');
            }

            $stream .= sprintf('^OS%02d', $index);
            $stream .= '^DI' . chr($length & 0xFF) . chr(($length >> 8) & 0xFF) . $payload;
        }

        $stream .= '^FF';

        return $stream;
    }

    private function sanitizeData(string $value): string
    {
        // Template data is byte-oriented; trim and remove null bytes/control chars.
        $value = trim($value);
        $value = str_replace("\x00", '', $value);
        $value = preg_replace('/[\x01-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value) ?? '';

        return $value;
    }
}

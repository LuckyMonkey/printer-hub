<?php
declare(strict_types=1);

namespace PrinterHub;

use RuntimeException;

final class BatchCodec
{
    /**
     * @param array<string,mixed> $payload
     * @return array{values:list<string>,source:string}
     */
    public static function parsePayload(array $payload): array
    {
        $source = trim((string) ($payload['input'] ?? $payload['valuesText'] ?? ''));

        if ($source !== '') {
            return [
                'values' => self::normalizeValues(preg_split('/[\r\n,]+/', $source) ?: []),
                'source' => $source,
            ];
        }

        $values = $payload['values'] ?? [];
        if (is_string($values)) {
            $values = preg_split('/[\r\n,]+/', $values) ?: [];
        }

        if (!is_array($values)) {
            throw new RuntimeException('Values must be a list, CSV string, or newline list.');
        }

        $normalized = self::normalizeValues($values);

        return [
            'values' => $normalized,
            'source' => implode("\r", $normalized),
        ];
    }

    /**
     * @param list<mixed> $values
     * @return list<string>
     */
    public static function normalizeValues(array $values): array
    {
        $normalized = [];
        foreach ($values as $raw) {
            $value = trim((string) $raw);
            if ($value === '') {
                continue;
            }

            $value = preg_replace('/[^\x20-\x7E]/', '', $value) ?? '';
            if ($value === '') {
                continue;
            }

            if (strlen($value) > 120) {
                throw new RuntimeException('Barcode values must be 120 characters or less.');
            }

            $normalized[] = $value;
        }

        return $normalized;
    }

    /**
     * @param list<string> $values
     */
    public static function toCsv(array $values): string
    {
        return implode(',', $values);
    }

    /**
     * @param list<string> $values
     */
    public static function toCr(array $values): string
    {
        return implode("\r", $values);
    }

    public static function csvToMultiline(string $csv): string
    {
        $parts = preg_split('/\s*,\s*/', trim($csv)) ?: [];
        $parts = self::normalizeValues($parts);
        return implode("\n", $parts);
    }
}

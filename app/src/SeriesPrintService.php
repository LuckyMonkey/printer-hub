<?php
declare(strict_types=1);

namespace PrinterHub;

use RuntimeException;

final class SeriesPrintService
{
    public function __construct(
        private readonly MultiPrinterPrintService $multiPrint,
        private readonly SeriesBarcodeRepository $series,
        private readonly JobLogger $logger
    ) {
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function printSeries(array $payload): array
    {
        $printerId = strtolower(trim((string) ($payload['printerId'] ?? '')));
        $labelType = strtolower(trim((string) ($payload['labelType'] ?? 'waco-id')));
        $prefix = trim((string) ($payload['prefix'] ?? ''));
        $textLine1 = trim((string) ($payload['textLine1'] ?? ''));
        $name = $this->optionalMeta($payload, 'name');
        $expiration = $this->optionalMeta($payload, 'expiration', ['expiraton']);
        $brand = $this->optionalMeta($payload, 'brand');
        $home = $this->optionalMeta($payload, 'home');
        $note = $this->optionalMeta($payload, 'note');

        $start = (int) ($payload['start'] ?? 1);
        $count = (int) ($payload['count'] ?? 10);
        $padLength = (int) ($payload['padLength'] ?? 6);

        if ($printerId === '') {
            throw new RuntimeException('printerId is required.');
        }

        if ($count < 1 || $count > 500) {
            throw new RuntimeException('count must be between 1 and 500.');
        }

        if ($start < 0) {
            throw new RuntimeException('start must be 0 or greater.');
        }

        if ($padLength < 1 || $padLength > 16) {
            throw new RuntimeException('padLength must be between 1 and 16.');
        }

        $seriesKey = sprintf('%s-%s-%d-%d-%d', $printerId, $labelType, $start, $count, $padLength);

        $results = [];
        $inserted = 0;
        $duplicates = 0;
        $sentCount = 0;
        $errorCount = 0;

        for ($i = 0; $i < $count; $i++) {
            $numeric = $start + $i;
            $barcode = $prefix . str_pad((string) $numeric, $padLength, '0', STR_PAD_LEFT);
            $hash = hash('sha256', $barcode);

            $print = $this->multiPrint->submit([
                'printerId' => $printerId,
                'labelType' => $labelType,
                'barcodeType' => 'CODE128',
                'barcodeValue' => $barcode,
                'textLine1' => $textLine1,
                'copies' => 1,
            ]);

            $status = (string) ($print['status'] ?? 'unknown');
            $jobId = isset($print['jobId']) ? (string) $print['jobId'] : null;
            $error = isset($print['error']) ? (string) $print['error'] : null;

            $insert = $this->series->insertIfNew(
                barcodeHash: $hash,
                barcodeValue: $barcode,
                name: $name,
                expiration: $expiration,
                brand: $brand,
                home: $home,
                note: $note,
                seriesKey: $seriesKey,
                printerId: $printerId,
                labelType: $labelType,
                jobId: $jobId,
                printStatus: $status,
                errorMessage: $error
            );

            if ($insert['inserted']) {
                $inserted++;
            } else {
                $duplicates++;
            }

            if ($status === 'sent') {
                $sentCount++;
            } else {
                $errorCount++;
            }

            $results[] = [
                'barcode' => $barcode,
                'hash' => $hash,
                'name' => $name ?? '',
                'expiration' => $expiration ?? '',
                'brand' => $brand ?? '',
                'home' => $home ?? '',
                'note' => $note ?? '',
                'jobId' => $jobId,
                'status' => $status,
                'error' => $error,
                'stored' => $insert['inserted'],
            ];
        }

        $this->logger->log('series-' . md5($seriesKey), 'series_print_complete', [
            'seriesKey' => $seriesKey,
            'printerId' => $printerId,
            'labelType' => $labelType,
            'name' => $name ?? '',
            'expiration' => $expiration ?? '',
            'brand' => $brand ?? '',
            'home' => $home ?? '',
            'note' => $note ?? '',
            'count' => $count,
            'inserted' => $inserted,
            'duplicates' => $duplicates,
            'sentCount' => $sentCount,
            'errorCount' => $errorCount,
        ]);

        return [
            'seriesKey' => $seriesKey,
            'code128Only' => true,
            'count' => $count,
            'inserted' => $inserted,
            'duplicates' => $duplicates,
            'sentCount' => $sentCount,
            'errorCount' => $errorCount,
            'meta' => [
                'name' => $name ?? '',
                'expiration' => $expiration ?? '',
                'brand' => $brand ?? '',
                'home' => $home ?? '',
                'note' => $note ?? '',
            ],
            'results' => $results,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function listSeries(int $limit = 200): array
    {
        return $this->series->listRecent($limit);
    }

    /**
     * @param array<string,mixed> $payload
     * @param list<string> $aliases
     */
    private function optionalMeta(array $payload, string $key, array $aliases = []): ?string
    {
        $candidates = [$key, strtoupper($key)];
        foreach ($aliases as $alias) {
            $candidates[] = $alias;
            $candidates[] = strtoupper($alias);
        }
        $candidates = array_values(array_unique($candidates));

        foreach ($candidates as $candidate) {
            if (!array_key_exists($candidate, $payload)) {
                continue;
            }

            $value = trim((string) $payload[$candidate]);
            return $value !== '' ? $value : null;
        }

        return null;
    }
}

<?php
declare(strict_types=1);

namespace PrinterHub;

use RuntimeException;

final class BatchPrintService
{
    public function __construct(
        private readonly MultiPrinterPrintService $multiPrint,
        private readonly BatchRepository $batches,
        private readonly JobLogger $logger
    ) {
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function saveAndPrintEarly(array $payload): array
    {
        $printerId = strtolower(trim((string) ($payload['printerId'] ?? '')));
        $labelType = strtolower(trim((string) ($payload['labelType'] ?? 'waco-id')));
        $input = trim((string) ($payload['input'] ?? ''));
        $textLine1 = trim((string) ($payload['textLine1'] ?? ''));

        if ($printerId === '') {
            throw new RuntimeException('printerId is required.');
        }

        if ($input === '') {
            throw new RuntimeException('input is required (CSV/newline list).');
        }

        $values = BatchCodec::normalizeValues(preg_split('/[\r\n,]+/', $input) ?: []);
        if ($values === []) {
            throw new RuntimeException('No valid barcode values were found in input.');
        }

        if (count($values) > 500) {
            throw new RuntimeException('Batch early print supports up to 500 barcodes per request.');
        }

        $printSummaries = [];
        $sentCount = 0;
        $errorCount = 0;

        foreach ($values as $value) {
            $printPayload = [
                'printerId' => $printerId,
                'labelType' => $labelType,
                'barcodeType' => 'CODE128',
                'barcodeValue' => $value,
                'textLine1' => $textLine1,
                'copies' => 1,
            ];

            $result = $this->multiPrint->submit($printPayload);
            $printSummaries[] = [
                'barcode' => $value,
                'jobId' => $result['jobId'] ?? null,
                'status' => $result['status'] ?? 'unknown',
                'error' => $result['error'] ?? null,
            ];

            if (($result['status'] ?? '') === 'sent') {
                $sentCount++;
            } else {
                $errorCount++;
            }
        }

        $batch = $this->batches->create(
            printerKey: $printerId,
            symbology: 'code128',
            values: $values,
            sourceInput: $input,
            siteLat: null,
            siteLon: null,
            printJobOutput: json_encode($printSummaries, JSON_THROW_ON_ERROR)
        );

        $this->logger->log('batch-early-' . (string) $batch['id'], 'saved_printed_batch', [
            'printerId' => $printerId,
            'labelType' => $labelType,
            'count' => count($values),
            'sentCount' => $sentCount,
            'errorCount' => $errorCount,
        ]);

        return [
            'saved' => true,
            'batchId' => $batch['id'],
            'createdAt' => $batch['createdAt'],
            'count' => count($values),
            'sentCount' => $sentCount,
            'errorCount' => $errorCount,
            'results' => $printSummaries,
            'note' => 'All batch early prints are forced to CODE128.',
        ];
    }
}

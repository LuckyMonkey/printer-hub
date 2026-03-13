<?php
declare(strict_types=1);

namespace PrinterHub;

use RuntimeException;

final class BatchPrintService
{
    public function __construct(
        private readonly MultiPrinterPrintService $multiPrint,
        private readonly BatchRepository $batches,
        private readonly JobLogger $logger,
        private readonly SheetsBackupService $sheetsBackup,
        private readonly PrinterRegistry $registry,
        private readonly CupsTransport $cupsTransport,
        private readonly RawSocketTransport $socketTransport,
        private readonly ZebraLabelService $zebra,
        private readonly HpBatchPdfService $hpBatchPdf
    ) {
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function saveAndPrintEarly(array $payload): array
    {
        $printerId = $this->normalizePrinterId((string) ($payload['printerId'] ?? ''));
        $labelType = strtolower(trim((string) ($payload['labelType'] ?? 'waco-id')));
        $barcodeType = $this->normalizeBarcodeType((string) ($payload['barcodeType'] ?? $payload['symbology'] ?? 'CODE128'));
        $input = trim((string) ($payload['input'] ?? $payload['barcodeData'] ?? $payload['valuesText'] ?? ''));

        if ($printerId === '') {
            throw new RuntimeException('printerId is required.');
        }

        $batchRules = $this->batchRulesFor($printerId);

        if ($input === '') {
            throw new RuntimeException('input is required (CSV/newline list).');
        }

        $values = BatchCodec::normalizeValues(preg_split('/[\r\n,]+/', $input) ?: []);
        if ($values === []) {
            throw new RuntimeException('No valid barcode values were found in input.');
        }

        if (count($values) > $batchRules['maxValues']) {
            throw new RuntimeException(sprintf('Batch supports 1 to %d barcode values.', $batchRules['maxValues']));
        }

        $this->validateBatchValues($values, $barcodeType);

        $printSummaries = $this->printBatch(
            printerId: $printerId,
            labelType: $labelType,
            barcodeType: $barcodeType,
            values: $values
        );

        $sentCount = 0;
        $errorCount = 0;
        foreach ($printSummaries as $summary) {
            if (($summary['status'] ?? '') === 'sent') {
                $sentCount++;
            } else {
                $errorCount++;
            }
        }

        $batch = $this->batches->create(
            printerKey: $printerId,
            symbology: strtolower($barcodeType),
            values: $values,
            sourceInput: BatchCodec::toCsv($values),
            siteLat: null,
            siteLon: null,
            printJobOutput: json_encode($printSummaries, JSON_THROW_ON_ERROR)
        );

        $backup = $this->sheetsBackup->backup([
            'batchId' => $batch['id'],
            'printer' => $printerId,
            'labelType' => $labelType,
            'symbology' => strtolower($barcodeType),
            'count' => count($values),
            'csv' => BatchCodec::toCsv($values),
            'carriageReturn' => BatchCodec::toCr($values),
            'values' => $values,
            'createdAt' => $batch['createdAt'],
        ]);

        $this->batches->markSheetsBackup(
            (int) $batch['id'],
            (string) $backup['status'],
            $backup['response']
        );

        $this->logger->log('batch-early-' . (string) $batch['id'], 'saved_printed_batch', [
            'printerId' => $printerId,
            'labelType' => $labelType,
            'barcodeType' => $barcodeType,
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
            'sheetsBackup' => $backup,
            'results' => $printSummaries,
            'barcodeType' => $barcodeType,
            'csv' => BatchCodec::toCsv($values),
            'rules' => [
                'singleSymbologyPerBatch' => true,
                'chunkSize' => $batchRules['chunkSize'],
                'maxValues' => $batchRules['maxValues'],
                'chunking' => $this->allChunkRules(),
            ],
        ];
    }

    /**
     * @param list<string> $values
     * @return list<array<string,mixed>>
     */
    private function printBatch(string $printerId, string $labelType, string $barcodeType, array $values): array
    {
        if ($printerId === 'brother-ql820') {
            return $this->printBrotherBatch($labelType, $barcodeType, $values);
        }

        if ($printerId === 'zebra-zp505') {
            return $this->printZebraBatch($labelType, $barcodeType, $values);
        }

        if ($printerId === 'hp-envy-5055') {
            return $this->printHpBatch($labelType, $barcodeType, $values);
        }

        throw new RuntimeException(sprintf('Unsupported printerId "%s" for batch printing.', $printerId));
    }

    /**
     * @param list<string> $values
     * @return list<array<string,mixed>>
     */
    private function printBrotherBatch(string $labelType, string $barcodeType, array $values): array
    {
        $results = [];
        foreach ($values as $value) {
            try {
                $result = $this->multiPrint->submit([
                    'printerId' => 'brother-ql820',
                    'labelType' => $labelType,
                    'barcodeType' => $barcodeType,
                    'barcodeValue' => $value,
                    'textLine1' => '',
                    'copies' => 1,
                ]);

                $results[] = [
                    'chunk' => null,
                    'barcode' => $value,
                    'jobId' => $result['jobId'] ?? null,
                    'status' => $result['status'] ?? 'unknown',
                    'error' => $result['error'] ?? null,
                ];
            } catch (RuntimeException $e) {
                $results[] = [
                    'chunk' => null,
                    'barcode' => $value,
                    'jobId' => null,
                    'status' => 'error',
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * @param list<string> $values
     * @return list<array<string,mixed>>
     */
    private function printZebraBatch(string $labelType, string $barcodeType, array $values): array
    {
        $printer = $this->registry->getPrinter('zebra-zp505');
        $transport = strtolower((string) ($printer['transport'] ?? 'cups'));
        $chunks = array_chunk($values, $this->batchRulesFromPrinter($printer)['chunkSize']);
        $results = [];

        foreach ($chunks as $chunkIndex => $chunkValues) {
            $chunkNo = $chunkIndex + 1;
            $zpl = $this->zebra->buildBatchGridZpl($labelType, $barcodeType, $chunkValues);

            try {
                if ($transport === 'socket') {
                    $socket = $this->socketTransport->send(
                        host: (string) ($printer['host'] ?? ''),
                        port: (int) ($printer['port'] ?? 9100),
                        bytes: $zpl,
                        timeoutSeconds: $this->registry->socketTimeoutSeconds()
                    );

                    $results[] = [
                        'chunk' => $chunkNo,
                        'count' => count($chunkValues),
                        'status' => 'sent',
                        'transport' => 'socket',
                        'bytesSent' => $socket['bytesSent'],
                        'error' => null,
                    ];
                    continue;
                }

                $queue = trim((string) ($printer['cupsQueueName'] ?? ''));
                if ($queue === '') {
                    throw new RuntimeException('Zebra cupsQueueName is not configured.');
                }

                $cups = $this->cupsTransport->sendRawBytes(
                    $queue,
                    $zpl,
                    sprintf('zebra-batch-%03d', $chunkNo),
                    1
                );

                $results[] = [
                    'chunk' => $chunkNo,
                    'count' => count($chunkValues),
                    'status' => 'sent',
                    'transport' => 'cups',
                    'jobOutput' => $cups['jobOutput'],
                    'error' => null,
                ];
            } catch (RuntimeException $e) {
                $results[] = [
                    'chunk' => $chunkNo,
                    'count' => count($chunkValues),
                    'status' => 'error',
                    'transport' => $transport,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * @param list<string> $values
     * @return list<array<string,mixed>>
     */
    private function printHpBatch(string $labelType, string $barcodeType, array $values): array
    {
        $printer = $this->registry->getPrinter('hp-envy-5055');
        $queue = trim((string) ($printer['cupsQueueName'] ?? ''));
        if ($queue === '') {
            throw new RuntimeException('HP cupsQueueName is not configured.');
        }

        $chunks = array_chunk($values, $this->batchRulesFromPrinter($printer)['chunkSize']);
        $results = [];

        foreach ($chunks as $chunkIndex => $chunkValues) {
            $chunkNo = $chunkIndex + 1;
            try {
                $pdfFile = $this->hpBatchPdf->buildSheetPdf(
                    values: $chunkValues,
                    barcodeType: $barcodeType,
                    labelType: $labelType,
                    pageNumber: $chunkNo
                );

                $cups = $this->cupsTransport->sendFile(
                    $queue,
                    $pdfFile,
                    sprintf('hp-batch-page-%03d', $chunkNo),
                    1
                );

                $results[] = [
                    'chunk' => $chunkNo,
                    'count' => count($chunkValues),
                    'status' => 'sent',
                    'transport' => 'cups',
                    'file' => $pdfFile,
                    'jobOutput' => $cups['jobOutput'],
                    'error' => null,
                ];
            } catch (RuntimeException $e) {
                $results[] = [
                    'chunk' => $chunkNo,
                    'count' => count($chunkValues),
                    'status' => 'error',
                    'transport' => 'cups',
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * @param list<string> $values
     */
    private function validateBatchValues(array $values, string $barcodeType): void
    {
        foreach ($values as $value) {
            if ($barcodeType === 'UPCA' && !preg_match('/^\d{11,12}$/', $value)) {
                throw new RuntimeException(sprintf('Invalid UPC-A value in batch: "%s". UPC-A requires 11 or 12 digits.', $value));
            }

            if ($barcodeType === 'QR' && strlen($value) > 300) {
                throw new RuntimeException('QR values must be 300 characters or less.');
            }
        }
    }

    private function normalizePrinterId(string $printerId): string
    {
        $id = strtolower(trim($printerId));
        return match ($id) {
            'zebra', 'zebra-zp505' => 'zebra-zp505',
            'brother', 'brother-ql820', 'brother-ql820nwb' => 'brother-ql820',
            'hp', 'hp-envy-5055', 'hp_envy_5055' => 'hp-envy-5055',
            default => $id,
        };
    }

    private function normalizeBarcodeType(string $barcodeType): string
    {
        $type = strtoupper(trim($barcodeType));
        return match ($type) {
            'CODE128', '128' => 'CODE128',
            'UPCA', 'UPC', 'UPC-A' => 'UPCA',
            'QR', 'QRCODE', 'QR-CODE' => 'QR',
            default => throw new RuntimeException('barcodeType must be CODE128, UPCA, or QR.'),
        };
    }

    /**
     * @return array{chunkSize:int,maxValues:int}
     */
    private function batchRulesFor(string $printerId): array
    {
        return $this->batchRulesFromPrinter($this->registry->getPrinter($printerId));
    }

    /**
     * @param array<string,mixed> $printer
     * @return array{chunkSize:int,maxValues:int}
     */
    private function batchRulesFromPrinter(array $printer): array
    {
        $batch = is_array($printer['batch'] ?? null) ? $printer['batch'] : [];

        return [
            'chunkSize' => max(1, (int) ($batch['chunkSize'] ?? 1)),
            'maxValues' => max(1, (int) ($batch['maxValues'] ?? 120)),
        ];
    }

    /**
     * @return array<string,int>
     */
    private function allChunkRules(): array
    {
        $rules = [];
        foreach (['zebra-zp505', 'hp-envy-5055', 'brother-ql820'] as $printerId) {
            try {
                $rules[$printerId] = $this->batchRulesFor($printerId)['chunkSize'];
            } catch (RuntimeException) {
                continue;
            }
        }

        return $rules;
    }
}

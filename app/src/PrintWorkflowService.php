<?php
declare(strict_types=1);

namespace PrinterHub;

use RuntimeException;

final class PrintWorkflowService
{
    public const PRINTER_BROTHER = 'brother';
    public const PRINTER_ZEBRA = 'zebra';
    public const PRINTER_HP = 'hp';
    public const ZEBRA_RENDER_AUTO = 'auto';
    public const ZEBRA_RENDER_Z64 = 'z64';
    public const ZEBRA_RENDER_NATIVE = 'native';

    private const TEMPLATE_SINGLE_SMALL = 'single_2_4x1_1';
    private const TEMPLATE_AVERY_SHEET = 'avery_3x10';

    public function __construct(
        private readonly CommandRunner $commands,
        private readonly BatchRepository $batches,
        private readonly SheetsBackupService $sheetsBackup,
        private readonly ZplRasterService $zplRaster
    ) {
    }

    /** @return array<string,mixed> */
    public function health(): array
    {
        try {
            $this->commands->mustRun(['sudo', 'lpstat', '-r']);
            $cups = 'ok';
        } catch (RuntimeException $e) {
            $cups = 'error: ' . $e->getMessage();
        }

        try {
            $this->batches->ensureSchema();
            $db = 'ok';
        } catch (RuntimeException $e) {
            $db = 'error: ' . $e->getMessage();
        }

        return [
            'ok' => true,
            'service' => 'printer-hub',
            'cups' => $cups,
            'database' => $db,
            'timezone' => getenv('TZ') ?: 'unset',
        ];
    }

    /** @return array<string,mixed> */
    public function config(): array
    {
        return [
            'queues' => [
                self::PRINTER_BROTHER => $this->queueName(self::PRINTER_BROTHER),
                self::PRINTER_ZEBRA => $this->queueName(self::PRINTER_ZEBRA),
                self::PRINTER_HP => $this->queueName(self::PRINTER_HP),
            ],
            'limits' => [
                self::PRINTER_BROTHER => 1,
                self::PRINTER_ZEBRA => 12,
                self::PRINTER_HP => 30,
            ],
            'symbology' => ['code128', 'qr', 'upc'],
            'zebraRenderModes' => [
                self::ZEBRA_RENDER_AUTO,
                self::ZEBRA_RENDER_Z64,
                self::ZEBRA_RENDER_NATIVE,
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function submitBrother(array $payload): array
    {
        return $this->submitPdfWorkflow(
            payload: $payload,
            printerKey: self::PRINTER_BROTHER,
            requiredCount: 1,
            template: self::TEMPLATE_SINGLE_SMALL,
            titleDefault: 'brother-single-barcode'
        );
    }

    /** @return array<string,mixed> */
    public function submitHp(array $payload): array
    {
        return $this->submitPdfWorkflow(
            payload: $payload,
            printerKey: self::PRINTER_HP,
            requiredCount: 30,
            template: self::TEMPLATE_AVERY_SHEET,
            titleDefault: 'hp-avery-3x10-sheet'
        );
    }

    /** @return array<string,mixed> */
    public function submitZebra(array $payload): array
    {
        $parsed = BatchCodec::parsePayload($payload);
        $values = $this->validatedValues($parsed['values'], (string) ($payload['symbology'] ?? 'code128'));
        $symbology = strtolower(trim((string) ($payload['symbology'] ?? 'code128')));
        $requestedRenderMode = strtolower(trim((string) ($payload['zebraMode'] ?? self::ZEBRA_RENDER_AUTO)));

        if (count($values) !== 12) {
            throw new RuntimeException('Zebra page requires exactly 12 barcodes.');
        }

        if (!in_array($requestedRenderMode, [
            self::ZEBRA_RENDER_AUTO,
            self::ZEBRA_RENDER_Z64,
            self::ZEBRA_RENDER_NATIVE,
        ], true)) {
            throw new RuntimeException('zebraMode must be auto, z64, or native.');
        }

        $queue = $this->queueName(self::PRINTER_ZEBRA);
        $title = trim((string) ($payload['title'] ?? 'zebra-4x6-12up'));
        $copies = $this->parseCopies($payload);

        $tmpDir = '/tmp/printer-hub';
        if (!is_dir($tmpDir) && !mkdir($tmpDir, 0775, true) && !is_dir($tmpDir)) {
            throw new RuntimeException('Unable to create temporary print directory.');
        }

        $file = sprintf('%s/%s.zpl', $tmpDir, uniqid('zebra_12_', true));
        [$zpl, $effectiveRenderMode] = $this->buildZebraSubmissionZpl($values, $symbology, $requestedRenderMode);
        file_put_contents($file, $zpl);

        $jobOutput = $this->commands->mustRun([
            'sudo',
            'lp',
            '-d', $queue,
            '-t', $title,
            '-n', (string) $copies,
            '-o', 'raw',
            $file,
        ]);

        $result = $this->persistAndBackup(
            printerKey: self::PRINTER_ZEBRA,
            symbology: $symbology,
            values: $values,
            sourceInput: $parsed['source'],
            jobOutput: $jobOutput,
            printerQueue: $queue,
            file: $file
        );
        $result['zebraRenderMode'] = $effectiveRenderMode;

        return $result;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listBatches(?string $printerKey, int $limit): array
    {
        return $this->batches->listRecent($printerKey, $limit);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function submitPdfWorkflow(
        array $payload,
        string $printerKey,
        int $requiredCount,
        string $template,
        string $titleDefault
    ): array {
        $parsed = BatchCodec::parsePayload($payload);
        $symbology = strtolower(trim((string) ($payload['symbology'] ?? 'code128')));
        $values = $this->validatedValues($parsed['values'], $symbology);

        if (count($values) !== $requiredCount) {
            throw new RuntimeException(sprintf('%s page requires exactly %d barcodes.', strtoupper($printerKey), $requiredCount));
        }

        $queue = $this->queueName($printerKey);
        $title = trim((string) ($payload['title'] ?? $titleDefault));
        $copies = $this->parseCopies($payload);

        $tmpDir = '/tmp/printer-hub';
        if (!is_dir($tmpDir) && !mkdir($tmpDir, 0775, true) && !is_dir($tmpDir)) {
            throw new RuntimeException('Unable to create temporary print directory.');
        }

        $textFile = sprintf('%s/%s.txt', $tmpDir, uniqid('job_', true));
        file_put_contents($textFile, $this->buildSimpleTextDocument($template, $symbology, $values));

        $jobOutput = $this->commands->mustRun([
            'sudo',
            'lp',
            '-d', $queue,
            '-t', $title,
            '-n', (string) $copies,
            $textFile,
        ]);

        return $this->persistAndBackup(
            printerKey: $printerKey,
            symbology: $symbology,
            values: $values,
            sourceInput: $parsed['source'],
            jobOutput: $jobOutput,
            printerQueue: $queue,
            file: $textFile
        );
    }

    /**
     * @param list<string> $values
     * @return array<string,mixed>
     */
    private function persistAndBackup(
        string $printerKey,
        string $symbology,
        array $values,
        string $sourceInput,
        string $jobOutput,
        string $printerQueue,
        string $file
    ): array {
        $siteLat = $this->envFloat('SITE_LAT');
        $siteLon = $this->envFloat('SITE_LON');

        $batch = $this->batches->create(
            printerKey: $printerKey,
            symbology: $symbology,
            values: $values,
            sourceInput: $sourceInput,
            siteLat: $siteLat,
            siteLon: $siteLon,
            printJobOutput: $jobOutput
        );

        $backupResult = $this->sheetsBackup->backup([
            'batchId' => $batch['id'],
            'printer' => $printerKey,
            'printerQueue' => $printerQueue,
            'symbology' => $symbology,
            'count' => count($values),
            'csv' => BatchCodec::toCsv($values),
            'carriageReturn' => BatchCodec::toCr($values),
            'values' => $values,
            'createdAt' => $batch['createdAt'],
        ]);

        $this->batches->markSheetsBackup(
            (int) $batch['id'],
            (string) $backupResult['status'],
            $backupResult['response']
        );

        return [
            'submitted' => true,
            'printer' => $printerKey,
            'queue' => $printerQueue,
            'jobOutput' => $jobOutput,
            'file' => $file,
            'batchId' => $batch['id'],
            'sheetsBackup' => $backupResult,
            'savedCsv' => BatchCodec::toCsv($values),
            'savedCarriageReturn' => BatchCodec::toCr($values),
            'restoredMultiline' => implode("\n", $values),
        ];
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function validatedValues(array $values, string $symbology): array
    {
        $symbology = strtolower(trim($symbology));
        if (!in_array($symbology, ['code128', 'qr', 'upc'], true)) {
            throw new RuntimeException('Symbology must be code128, qr, or upc.');
        }

        if ($values === []) {
            throw new RuntimeException('At least one barcode value is required.');
        }

        foreach ($values as $value) {
            if ($symbology === 'upc' && !preg_match('/^\d{11,12}$/', $value)) {
                throw new RuntimeException('UPC values must be 11 or 12 digits.');
            }
        }

        return $values;
    }

    /** @param list<string> $values */
    private function buildZebra12UpZpl(array $values, string $symbology): string
    {
        $symbology = strtolower($symbology);
        $xPositions = [24, 286, 548];
        $yPositions = [42, 332, 622, 912];

        $lines = [
            '^XA',
            '^CI28',
            '^PW812',
            '^LL1218',
            '^LH0,0',
            '^FO28,8^A0N,28,28^FDZebra ZP505 4x6 - 12 Barcodes^FS',
        ];

        foreach ($values as $index => $value) {
            $row = intdiv($index, 3);
            $col = $index % 3;

            $x = $xPositions[$col];
            $y = $yPositions[$row];
            $safe = $this->escapeZpl($value);

            if ($symbology === 'qr') {
                $lines[] = sprintf('^FO%d,%d^BQN,2,3^FDLA,%s^FS', $x, $y + 4, $safe);
                $lines[] = sprintf('^FO%d,%d^A0N,20,20^FD%s^FS', $x, $y + 132, $safe);
                continue;
            }

            if ($symbology === 'upc') {
                $lines[] = '^BY2,2,58';
                $lines[] = sprintf('^FO%d,%d^BUN,58,N,N^FD%s^FS', $x, $y + 8, $safe);
                $lines[] = sprintf('^FO%d,%d^A0N,20,20^FD%s^FS', $x, $y + 122, $safe);
                continue;
            }

            $lines[] = '^BY2,2,58';
            $lines[] = sprintf('^FO%d,%d^BCN,58,N,N,N^FD%s^FS', $x, $y + 8, $safe);
            $lines[] = sprintf('^FO%d,%d^A0N,20,20^FD%s^FS', $x, $y + 122, $safe);
        }

        $lines[] = '^XZ';

        return implode("\n", $lines) . "\n";
    }

    private function escapeZpl(string $value): string
    {
        return str_replace(['^', '~'], [' ', ' '], $value);
    }

    /**
     * @param list<string> $values
     * @return array{0:string,1:string}
     */
    private function buildZebraSubmissionZpl(array $values, string $symbology, string $requestedRenderMode): array
    {
        $symbology = strtolower(trim($symbology));

        if ($requestedRenderMode === self::ZEBRA_RENDER_NATIVE) {
            return [$this->buildZebra12UpZpl($values, $symbology), self::ZEBRA_RENDER_NATIVE];
        }

        if ($requestedRenderMode === self::ZEBRA_RENDER_Z64) {
            if ($symbology === 'qr') {
                throw new RuntimeException('Raster Z64 mode does not support QR yet. Use zebraMode=native or zebraMode=auto.');
            }
            return [$this->zplRaster->build12UpZ64($values, $symbology), self::ZEBRA_RENDER_Z64];
        }

        if ($symbology === 'qr') {
            return [$this->buildZebra12UpZpl($values, $symbology), self::ZEBRA_RENDER_NATIVE];
        }

        return [$this->zplRaster->build12UpZ64($values, $symbology), self::ZEBRA_RENDER_Z64];
    }

    /** @param array<string,mixed> $payload */
    private function parseCopies(array $payload): int
    {
        $copies = (int) ($payload['copies'] ?? 1);
        if ($copies < 1 || $copies > 250) {
            throw new RuntimeException('Copies must be between 1 and 250.');
        }

        return $copies;
    }

    private function queueName(string $printerKey): string
    {
        $mapping = [
            self::PRINTER_BROTHER => getenv('PRINTER_BROTHER_QUEUE') ?: 'brother_ql820nwb',
            self::PRINTER_ZEBRA => getenv('PRINTER_ZEBRA_QUEUE') ?: 'zebra_zp505',
            self::PRINTER_HP => getenv('PRINTER_HP_QUEUE') ?: 'hp_envy_5055',
        ];

        $queue = trim((string) ($mapping[$printerKey] ?? ''));
        if ($queue === '') {
            throw new RuntimeException(sprintf('Queue for printer key "%s" is not configured.', $printerKey));
        }

        return $queue;
    }

    private function envFloat(string $name): ?float
    {
        $raw = getenv($name);
        if ($raw === false || $raw === '') {
            return null;
        }

        if (!is_numeric($raw)) {
            return null;
        }

        return (float) $raw;
    }

    /**
     * @param list<string> $values
     */
    private function buildSimpleTextDocument(string $template, string $symbology, array $values): string
    {
        $lines = [
            'Printer Hub',
            sprintf('Template: %s', $template),
            sprintf('Symbology: %s', strtoupper($symbology)),
            sprintf('Count: %d', count($values)),
            '',
        ];

        foreach ($values as $index => $value) {
            $lines[] = sprintf('%d. %s', $index + 1, $value);
        }

        return implode("\n", $lines) . "\n";
    }
}

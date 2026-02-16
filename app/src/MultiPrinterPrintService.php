<?php
declare(strict_types=1);

namespace PrinterHub;

use RuntimeException;

final class MultiPrinterPrintService
{
    public function __construct(
        private readonly PrinterRegistry $registry,
        private readonly PrintJobRepository $jobs,
        private readonly JobLogger $logger,
        private readonly RawSocketTransport $socketTransport,
        private readonly CupsTransport $cupsTransport,
        private readonly ZebraLabelService $zebra,
        private readonly BrotherTemplateClient $brother,
        private readonly HpLabelService $hp
    ) {
    }

    /** @return array<string,mixed> */
    public function config(): array
    {
        return [
            'brotherMode' => $this->registry->brotherMode(),
            'printers' => $this->registry->listPublicConfig(),
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function submit(array $payload): array
    {
        $validated = $this->validatePayload($payload);

        $jobId = $this->generateJobId();
        $summary = [
            'printerId' => $validated['printerId'],
            'labelType' => $validated['labelType'],
            'barcodeType' => $validated['barcodeType'],
            'barcodeValue' => $validated['barcodeValue'],
            'textLine1' => $validated['textLine1'],
            'copies' => $validated['copies'],
        ];

        $this->jobs->createJob($jobId, $validated['printerId'], $validated['labelType'], $summary);
        $this->logger->log($jobId, 'queued', $summary);

        $this->jobs->updateStatus($jobId, 'sending');
        $this->logger->log($jobId, 'sending');

        try {
            $dispatch = $this->dispatch($validated, $jobId);

            $this->jobs->updateStatus($jobId, 'sent');
            $this->logger->log($jobId, 'sent', $dispatch);

            return [
                'jobId' => $jobId,
                'status' => 'sent',
                'detail' => $dispatch,
            ];
        } catch (RuntimeException $e) {
            $this->jobs->updateStatus($jobId, 'error', $e->getMessage());
            $this->jobs->recordError($validated['printerId'], $jobId, $e->getMessage(), $this->registry->errorHistoryLimit());
            $this->logger->log($jobId, 'error', ['message' => $e->getMessage()]);

            return [
                'jobId' => $jobId,
                'status' => 'error',
                'error' => $e->getMessage(),
            ];
        }
    }

    /** @return array<string,mixed> */
    public function status(string $jobId): array
    {
        $job = $this->jobs->getJob($jobId);
        if ($job === null) {
            throw new RuntimeException('Job not found.');
        }

        return [
            'job' => $job,
            'recentErrors' => $this->jobs->recentErrors((string) $job['printerId'], 5),
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function brotherDiagnostics(array $payload): array
    {
        $printer = $this->registry->getPrinter('brother-ql820');
        $host = (string) ($printer['host'] ?? '');
        $port = (int) ($printer['port'] ?? 9100);

        $ping = $this->socketTransport->ping($host, $port, $this->registry->socketTimeoutSeconds());

        $response = [
            'printerId' => 'brother-ql820',
            'transport' => (string) ($printer['transport'] ?? 'socket'),
            'ping' => $ping,
            'brotherMode' => $this->registry->brotherMode(),
            'test' => [
                'attempted' => false,
                'status' => 'skipped',
            ],
        ];

        $sendTest = (bool) ($payload['sendTest'] ?? false);
        if (!$sendTest) {
            return $response;
        }

        $labelType = strtolower(trim((string) ($payload['labelType'] ?? 'waco-id')));
        $barcodeValue = trim((string) ($payload['barcodeValue'] ?? '051000568235'));
        $textLine1 = trim((string) ($payload['textLine1'] ?? 'Spaghettios'));

        $response['test']['attempted'] = true;

        try {
            $dispatch = $this->sendBrother(
                printer: $printer,
                labelType: $labelType,
                barcodeType: 'CODE128',
                barcodeValue: $barcodeValue,
                textLine1: $textLine1,
                copies: 1,
                jobId: 'diag-' . $this->generateJobId()
            );
            $response['test']['status'] = 'sent';
            $response['test']['detail'] = $dispatch;
        } catch (RuntimeException $e) {
            $response['test']['status'] = 'error';
            $response['test']['error'] = $e->getMessage();
        }

        return $response;
    }

    /**
     * @param array<string,mixed> $validated
     * @return array<string,mixed>
     */
    private function dispatch(array $validated, string $jobId): array
    {
        $printer = $this->registry->getPrinter($validated['printerId']);

        if ($validated['printerId'] === 'zebra-zp505') {
            return $this->sendZebra(
                printer: $printer,
                labelType: $validated['labelType'],
                barcodeType: $validated['barcodeType'],
                barcodeValue: $validated['barcodeValue'],
                textLine1: $validated['textLine1'],
                copies: $validated['copies'],
                jobId: $jobId
            );
        }

        if ($validated['printerId'] === 'brother-ql820') {
            return $this->sendBrother(
                printer: $printer,
                labelType: $validated['labelType'],
                barcodeType: $validated['barcodeType'],
                barcodeValue: $validated['barcodeValue'],
                textLine1: $validated['textLine1'],
                copies: $validated['copies'],
                jobId: $jobId
            );
        }

        if ($validated['printerId'] === 'hp-envy-5055') {
            return $this->sendHp(
                printer: $printer,
                labelType: $validated['labelType'],
                barcodeType: $validated['barcodeType'],
                barcodeValue: $validated['barcodeValue'],
                textLine1: $validated['textLine1'],
                copies: $validated['copies'],
                jobId: $jobId
            );
        }

        throw new RuntimeException(sprintf('Unsupported printer: %s', $validated['printerId']));
    }

    /** @return array<string,mixed> */
    private function sendZebra(
        array $printer,
        string $labelType,
        string $barcodeType,
        string $barcodeValue,
        string $textLine1,
        int $copies,
        string $jobId
    ): array {
        $zpl = $this->zebra->buildZpl($labelType, $barcodeType, $barcodeValue, $textLine1);
        $transport = strtolower((string) ($printer['transport'] ?? 'cups'));

        if ($transport === 'socket') {
            $socket = $this->socketTransport->send(
                host: (string) ($printer['host'] ?? ''),
                port: (int) ($printer['port'] ?? 9100),
                bytes: $zpl,
                timeoutSeconds: $this->registry->socketTimeoutSeconds()
            );

            return [
                'transport' => 'socket',
                'jobId' => $jobId,
                'bytesSent' => $socket['bytesSent'],
                'host' => $socket['host'],
                'port' => $socket['port'],
            ];
        }

        $queue = trim((string) ($printer['cupsQueueName'] ?? ''));
        if ($queue === '') {
            throw new RuntimeException('Zebra cupsQueueName is not configured.');
        }

        $cups = $this->cupsTransport->sendRawBytes($queue, $zpl, sprintf('zebra-%s', $jobId), $copies);

        return [
            'transport' => 'cups',
            'jobId' => $jobId,
            'queue' => $cups['queue'],
            'jobOutput' => $cups['jobOutput'],
            'file' => $cups['file'],
        ];
    }

    /** @return array<string,mixed> */
    private function sendBrother(
        array $printer,
        string $labelType,
        string $barcodeType,
        string $barcodeValue,
        string $textLine1,
        int $copies,
        string $jobId
    ): array {
        if ($this->registry->brotherMode() === 'raster') {
            $queue = trim((string) ($printer['cupsQueueName'] ?? ''));
            if ($queue === '') {
                throw new RuntimeException('Brother raster mode requires cupsQueueName.');
            }

            $doc = $this->hp->buildTextDocument($labelType, $barcodeType, $barcodeValue, $textLine1, $copies);
            $cups = $this->cupsTransport->sendText($queue, $doc, sprintf('brother-raster-%s', $jobId), $copies);

            return [
                'transport' => 'cups',
                'mode' => 'raster',
                'jobOutput' => $cups['jobOutput'],
                'queue' => $cups['queue'],
                'file' => $cups['file'],
            ];
        }

        $templateMap = is_array($printer['templateMap'] ?? null) ? $printer['templateMap'] : [];
        if (!isset($templateMap[$labelType]) || !is_array($templateMap[$labelType])) {
            throw new RuntimeException(sprintf('Brother template mapping not configured for labelType "%s".', $labelType));
        }

        $template = $templateMap[$labelType];
        $templateId = (int) ($template['templateId'] ?? 0);
        $objectMap = is_array($template['objectMap'] ?? null) ? $template['objectMap'] : [];

        $barcodeObj = (int) ($objectMap['barcodeObjectIndex'] ?? 1);
        $textObj = (int) ($objectMap['textObjectIndex'] ?? 2);

        $stream = $this->brother->buildCommandStream(
            templateId: $templateId,
            objectDataByIndex: [
                $barcodeObj => $barcodeValue,
                $textObj => $textLine1,
            ],
            copies: $copies
        );

        $transport = strtolower((string) ($printer['transport'] ?? 'socket'));
        if ($transport === 'socket') {
            $host = trim((string) ($printer['host'] ?? ''));
            if (in_array(strtolower($host), ['127.0.0.1', 'localhost', '::1', '0.0.0.0', ''], true)) {
                throw new RuntimeException(
                    'Brother socket transport is targeting localhost. Set BROTHER_HOST to the Brother printer IP (for example 192.168.x.x) or switch BROTHER_TRANSPORT=cups with a valid queue.'
                );
            }

            $socket = $this->socketTransport->send(
                host: $host,
                port: (int) ($printer['port'] ?? 9100),
                bytes: $stream,
                timeoutSeconds: $this->registry->socketTimeoutSeconds()
            );

            return [
                'transport' => 'socket',
                'mode' => 'template',
                'templateId' => $templateId,
                'bytesSent' => $socket['bytesSent'],
                'host' => $socket['host'],
                'port' => $socket['port'],
            ];
        }

        $queue = trim((string) ($printer['cupsQueueName'] ?? ''));
        if ($queue === '') {
            throw new RuntimeException('Brother cupsQueueName is not configured.');
        }

        $cups = $this->cupsTransport->sendRawBytes($queue, $stream, sprintf('brother-template-%s', $jobId), 1);

        return [
            'transport' => 'cups',
            'mode' => 'template',
            'templateId' => $templateId,
            'jobOutput' => $cups['jobOutput'],
            'queue' => $cups['queue'],
            'file' => $cups['file'],
        ];
    }

    /** @return array<string,mixed> */
    private function sendHp(
        array $printer,
        string $labelType,
        string $barcodeType,
        string $barcodeValue,
        string $textLine1,
        int $copies,
        string $jobId
    ): array {
        $queue = trim((string) ($printer['cupsQueueName'] ?? ''));
        if ($queue === '') {
            throw new RuntimeException('HP cupsQueueName is not configured.');
        }

        $text = $this->hp->buildTextDocument($labelType, $barcodeType, $barcodeValue, $textLine1, $copies);
        $cups = $this->cupsTransport->sendText($queue, $text, sprintf('hp-%s', $jobId), $copies);

        return [
            'transport' => 'cups',
            'queue' => $cups['queue'],
            'jobOutput' => $cups['jobOutput'],
            'file' => $cups['file'],
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function validatePayload(array $payload): array
    {
        $printerId = strtolower(trim((string) ($payload['printerId'] ?? '')));
        if ($printerId === '') {
            throw new RuntimeException('printerId is required.');
        }

        $printer = $this->registry->getPrinter($printerId);

        $labelType = strtolower(trim((string) ($payload['labelType'] ?? '')));
        if ($labelType === '') {
            throw new RuntimeException('labelType is required.');
        }

        $labelTypes = is_array($printer['labelTypes'] ?? null) ? $printer['labelTypes'] : [];
        if (!isset($labelTypes[$labelType])) {
            throw new RuntimeException(sprintf('Unsupported labelType "%s" for printer "%s".', $labelType, $printerId));
        }

        $barcodeType = strtoupper(trim((string) ($payload['barcodeType'] ?? 'CODE128')));
        if (!in_array($barcodeType, ['CODE128', 'UPCA', 'QR'], true)) {
            throw new RuntimeException('barcodeType must be CODE128, UPCA, or QR.');
        }

        $capabilities = is_array($printer['capabilities'] ?? null) ? $printer['capabilities'] : [];
        $supportedBarcodeTypes = array_map('strtoupper', array_map('strval', (array) ($capabilities['barcodeTypes'] ?? ['CODE128'])));
        if (!in_array($barcodeType, $supportedBarcodeTypes, true)) {
            throw new RuntimeException(sprintf('Printer "%s" does not support barcodeType "%s".', $printerId, $barcodeType));
        }

        $barcodeValue = trim((string) ($payload['barcodeValue'] ?? ''));
        if ($barcodeValue === '') {
            throw new RuntimeException('barcodeValue is required.');
        }

        $maxBarcodeLength = (int) ($capabilities['maxBarcodeLength'] ?? 120);
        if (strlen($barcodeValue) > $maxBarcodeLength) {
            throw new RuntimeException(sprintf('barcodeValue exceeds max length (%d).', $maxBarcodeLength));
        }

        if ($barcodeType === 'UPCA' && !preg_match('/^\d{11,12}$/', $barcodeValue)) {
            throw new RuntimeException('UPCA barcodeValue must be 11 or 12 digits.');
        }

        if ($barcodeType === 'QR' && strlen($barcodeValue) > 300) {
            throw new RuntimeException('QR barcodeValue must be 300 characters or less.');
        }

        $textLine1 = trim((string) ($payload['textLine1'] ?? ''));
        $maxText = (int) ($capabilities['maxTextLine1Length'] ?? 120);
        if (strlen($textLine1) > $maxText) {
            throw new RuntimeException(sprintf('textLine1 exceeds max length (%d).', $maxText));
        }

        $copies = (int) ($payload['copies'] ?? 1);
        if ($copies < 1 || $copies > 250) {
            throw new RuntimeException('copies must be between 1 and 250.');
        }

        return [
            'printerId' => $printerId,
            'labelType' => $labelType,
            'barcodeType' => $barcodeType,
            'barcodeValue' => $barcodeValue,
            'textLine1' => $textLine1,
            'copies' => $copies,
        ];
    }

    private function generateJobId(): string
    {
        return bin2hex(random_bytes(8));
    }
}

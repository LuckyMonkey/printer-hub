<?php
declare(strict_types=1);

namespace PrinterHub;

use RuntimeException;

final class ZebraImagePrintService
{
    public function __construct(
        private readonly PrinterRegistry $registry,
        private readonly PrintJobRepository $jobs,
        private readonly JobLogger $logger,
        private readonly CupsTransport $cupsTransport,
        private readonly ZebraPngRasterService $raster
    ) {
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed>|null $upload
     * @return array<string,mixed>
     */
    public function submit(array $payload, ?array $upload = null): array
    {
        $jobId = bin2hex(random_bytes(8));
        $request = $this->normalizeRequest($payload, $upload);

        $summary = [
            'printerId' => 'zebra-zp505',
            'labelType' => 'png-image',
            'barcodeType' => 'PNG',
            'barcodeValue' => $request['sourceName'],
            'textLine1' => $request['title'],
            'copies' => $request['copies'],
            'sourceType' => $request['sourceType'],
            'transport' => $request['transport'],
        ];

        $this->jobs->createJob($jobId, 'zebra-zp505', 'png-image', $summary);
        $this->logger->log($jobId, 'queued', $summary);
        $this->jobs->updateStatus($jobId, 'sending');
        $this->logger->log($jobId, 'sending', [
            'sourceName' => $request['sourceName'],
            'transport' => $request['transport'],
        ]);

        try {
            $zpl = $this->raster->buildZplFromPngPath($request['pngPath'], $request['threshold']);
            $dispatch = $this->dispatch($request, $jobId, $zpl);

            $this->jobs->updateStatus($jobId, 'sent');
            $this->logger->log($jobId, 'sent', $dispatch);

            return [
                'jobId' => $jobId,
                'status' => 'sent',
                'detail' => $dispatch,
            ];
        } catch (RuntimeException $e) {
            $this->jobs->updateStatus($jobId, 'error', $e->getMessage());
            $this->jobs->recordError('zebra-zp505', $jobId, $e->getMessage(), $this->registry->errorHistoryLimit());
            $this->logger->log($jobId, 'error', ['message' => $e->getMessage()]);

            return [
                'jobId' => $jobId,
                'status' => 'error',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed>|null $upload
     * @return array{copies:int,devicePath:string,pngPath:string,queue:string,sourceName:string,sourceType:string,threshold:int,title:string,transport:string}
     */
    private function normalizeRequest(array $payload, ?array $upload): array
    {
        $printer = $this->registry->getPrinter('zebra-zp505');
        $requestedTransport = strtolower(trim((string) ($payload['transport'] ?? 'auto')));
        if (!in_array($requestedTransport, ['auto', 'cups', 'direct-usb'], true)) {
            throw new RuntimeException('transport must be auto, cups, or direct-usb.');
        }

        $configuredTransport = strtolower(trim((string) ($printer['imageTransport'] ?? 'direct-usb')));
        if (!in_array($configuredTransport, ['cups', 'direct-usb'], true)) {
            $configuredTransport = 'direct-usb';
        }

        $transport = $requestedTransport === 'auto' ? $configuredTransport : $requestedTransport;
        $queue = trim((string) ($printer['cupsQueueName'] ?? ''));
        $devicePath = trim((string) ($printer['usbDevicePath'] ?? ''));
        $threshold = (int) ($payload['threshold'] ?? ($printer['imageThreshold'] ?? 160));
        $copies = (int) ($payload['copies'] ?? 1);

        if ($copies < 1 || $copies > 20) {
            throw new RuntimeException('copies must be between 1 and 20.');
        }

        if ($transport === 'cups' && $queue === '') {
            throw new RuntimeException('Zebra cupsQueueName is not configured.');
        }

        if ($transport === 'direct-usb' && $devicePath === '') {
            throw new RuntimeException('Zebra usbDevicePath is not configured.');
        }

        $source = $this->resolvePngSource($payload, $upload);
        $title = trim((string) ($payload['title'] ?? ''));
        if ($title === '') {
            $base = pathinfo($source['sourceName'], PATHINFO_FILENAME);
            $title = $base !== '' ? sprintf('zebra-image-%s', $base) : 'zebra-image';
        }

        return [
            'copies' => $copies,
            'devicePath' => $devicePath,
            'pngPath' => $source['pngPath'],
            'queue' => $queue,
            'sourceName' => $source['sourceName'],
            'sourceType' => $source['sourceType'],
            'threshold' => $threshold,
            'title' => $title,
            'transport' => $transport,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed>|null $upload
     * @return array{pngPath:string,sourceName:string,sourceType:string}
     */
    private function resolvePngSource(array $payload, ?array $upload): array
    {
        if (is_array($upload) && $upload !== []) {
            $error = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($error !== UPLOAD_ERR_OK) {
                throw new RuntimeException(sprintf('PNG upload failed with code %d.', $error));
            }

            $tmpPath = trim((string) ($upload['tmp_name'] ?? ''));
            if ($tmpPath === '' || !is_file($tmpPath)) {
                throw new RuntimeException('Uploaded PNG file is not available on disk.');
            }

            $name = trim((string) ($upload['name'] ?? 'upload.png'));

            return [
                'pngPath' => $tmpPath,
                'sourceName' => $name !== '' ? $name : 'upload.png',
                'sourceType' => 'upload',
            ];
        }

        $pngPath = trim((string) ($payload['pngPath'] ?? ''));
        if ($pngPath !== '') {
            if (!is_file($pngPath)) {
                throw new RuntimeException(sprintf('pngPath does not exist: %s', $pngPath));
            }

            return [
                'pngPath' => $pngPath,
                'sourceName' => basename($pngPath),
                'sourceType' => 'path',
            ];
        }

        $imageBase64 = trim((string) ($payload['imageBase64'] ?? ''));
        if ($imageBase64 !== '') {
            if (preg_match('/^data:image\/png;base64,(.+)$/i', $imageBase64, $m)) {
                $imageBase64 = $m[1];
            }

            $decoded = base64_decode($imageBase64, true);
            if ($decoded === false) {
                throw new RuntimeException('imageBase64 must be valid base64 PNG content.');
            }

            $tmpDir = $this->ensureTempDir();
            $pngPath = sprintf('%s/%s.png', $tmpDir, uniqid('zebra_image_', true));
            if (file_put_contents($pngPath, $decoded) === false) {
                throw new RuntimeException('Unable to write temporary PNG payload.');
            }

            $name = trim((string) ($payload['filename'] ?? 'upload.png'));

            return [
                'pngPath' => $pngPath,
                'sourceName' => $name !== '' ? $name : 'upload.png',
                'sourceType' => 'base64',
            ];
        }

        throw new RuntimeException('Provide a PNG via multipart file upload, pngPath, or imageBase64.');
    }

    /**
     * @param array{copies:int,devicePath:string,pngPath:string,queue:string,sourceName:string,sourceType:string,threshold:int,title:string,transport:string} $request
     * @return array<string,mixed>
     */
    private function dispatch(array $request, string $jobId, string $zpl): array
    {
        if ($request['transport'] === 'cups') {
            $cups = $this->cupsTransport->sendRawBytes(
                $request['queue'],
                $zpl,
                $request['title'],
                $request['copies']
            );

            return [
                'transport' => 'cups',
                'jobId' => $jobId,
                'queue' => $cups['queue'],
                'jobOutput' => $cups['jobOutput'],
                'file' => $cups['file'],
                'sourceName' => $request['sourceName'],
                'sourceType' => $request['sourceType'],
            ];
        }

        $tmpDir = $this->ensureTempDir();
        $zplFile = sprintf('%s/%s.zpl', $tmpDir, uniqid('zebra_image_', true));
        if (file_put_contents($zplFile, $zpl) === false) {
            throw new RuntimeException('Unable to write temporary Zebra image ZPL file.');
        }

        $bytesWritten = 0;
        for ($copy = 0; $copy < $request['copies']; $copy++) {
            $bytesWritten += $this->writeDirectUsb($request['devicePath'], $zpl);
        }

        return [
            'transport' => 'direct-usb',
            'jobId' => $jobId,
            'devicePath' => $request['devicePath'],
            'bytesSent' => $bytesWritten,
            'copies' => $request['copies'],
            'file' => $zplFile,
            'sourceName' => $request['sourceName'],
            'sourceType' => $request['sourceType'],
        ];
    }

    private function writeDirectUsb(string $devicePath, string $bytes): int
    {
        if (!is_file($devicePath)) {
            throw new RuntimeException(sprintf('USB printer device does not exist: %s', $devicePath));
        }

        $handle = @fopen($devicePath, 'wb');
        if ($handle === false) {
            throw new RuntimeException(sprintf('Unable to open USB printer device: %s', $devicePath));
        }

        $remaining = $bytes;
        $written = 0;

        while ($remaining !== '') {
            $chunk = fwrite($handle, $remaining);
            if ($chunk === false) {
                fclose($handle);
                throw new RuntimeException(sprintf('Failed while writing to USB printer device: %s', $devicePath));
            }

            $written += $chunk;
            $remaining = (string) substr($remaining, $chunk);
        }

        fflush($handle);
        fclose($handle);

        return $written;
    }

    private function ensureTempDir(): string
    {
        $tmpDir = '/tmp/printer-hub';
        if (!is_dir($tmpDir) && !mkdir($tmpDir, 0775, true) && !is_dir($tmpDir)) {
            throw new RuntimeException('Unable to create /tmp/printer-hub.');
        }

        return $tmpDir;
    }
}

<?php
declare(strict_types=1);

namespace PrinterHub;

use RuntimeException;

final class PrinterRegistry
{
    /** @var array<string,mixed> */
    private array $config;

    public function __construct(?string $configPath = null)
    {
        $path = $configPath ?? __DIR__ . '/../config/printers.php';
        if (!is_file($path)) {
            throw new RuntimeException(sprintf('Printer config file not found: %s', $path));
        }

        $loaded = require $path;
        if (!is_array($loaded)) {
            throw new RuntimeException('Printer config file must return an array.');
        }

        $this->config = $loaded;
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return $this->config;
    }

    public function rateLimitPerMinute(): int
    {
        $limit = (int) ($this->config['rateLimitPerMinute'] ?? 60);
        return max(10, min($limit, 600));
    }

    public function errorHistoryLimit(): int
    {
        $limit = (int) ($this->config['errorHistoryLimit'] ?? 20);
        return max(5, min($limit, 200));
    }

    public function socketTimeoutSeconds(): float
    {
        $timeout = (float) ($this->config['socketTimeoutSeconds'] ?? 4.0);
        return max(1.0, min($timeout, 30.0));
    }

    public function brotherMode(): string
    {
        $mode = strtolower(trim((string) ($this->config['brotherMode'] ?? 'template')));
        return in_array($mode, ['template', 'raster'], true) ? $mode : 'template';
    }

    public function logPath(): string
    {
        $path = trim((string) ($this->config['logPath'] ?? '/var/log/printer-hub/print-jobs.log'));
        return $path !== '' ? $path : '/var/log/printer-hub/print-jobs.log';
    }

    /** @return array<string,mixed> */
    public function getPrinter(string $printerId): array
    {
        $printerId = trim($printerId);
        $printers = $this->config['printers'] ?? [];
        if (!is_array($printers) || !isset($printers[$printerId]) || !is_array($printers[$printerId])) {
            throw new RuntimeException(sprintf('Unknown printerId: %s', $printerId));
        }

        $printer = $printers[$printerId];
        $printer['printerId'] = $printerId;

        return $printer;
    }

    /** @return list<array<string,mixed>> */
    public function listPublicConfig(): array
    {
        $printers = $this->config['printers'] ?? [];
        if (!is_array($printers)) {
            return [];
        }

        $result = [];
        foreach ($printers as $printerId => $printer) {
            if (!is_array($printer)) {
                continue;
            }

            $labelTypes = [];
            foreach (($printer['labelTypes'] ?? []) as $labelType => $meta) {
                if (!is_array($meta)) {
                    continue;
                }
                $labelTypes[] = [
                    'id' => (string) $labelType,
                    'label' => (string) ($meta['label'] ?? $labelType),
                    'description' => (string) ($meta['description'] ?? ''),
                ];
            }

            $capabilities = is_array($printer['capabilities'] ?? null) ? $printer['capabilities'] : [];
            $batch = is_array($printer['batch'] ?? null) ? $printer['batch'] : [];

            $result[] = [
                'printerId' => (string) $printerId,
                'displayName' => (string) ($printer['displayName'] ?? $printerId),
                'transport' => (string) ($printer['transport'] ?? 'cups'),
                'cupsQueueName' => (string) ($printer['cupsQueueName'] ?? ''),
                'host' => (string) ($printer['host'] ?? ''),
                'port' => (int) ($printer['port'] ?? 0),
                'labelTypes' => $labelTypes,
                'capabilities' => [
                    'barcodeTypes' => array_values(array_map('strval', (array) ($capabilities['barcodeTypes'] ?? ['CODE128']))),
                    'maxBarcodeLength' => (int) ($capabilities['maxBarcodeLength'] ?? 120),
                    'maxTextLine1Length' => (int) ($capabilities['maxTextLine1Length'] ?? 120),
                ],
                'batch' => [
                    'enabled' => (bool) ($batch['enabled'] ?? false),
                    'chunkSize' => max(1, (int) ($batch['chunkSize'] ?? 1)),
                    'maxValues' => max(1, (int) ($batch['maxValues'] ?? 120)),
                    'defaultBarcodeType' => (string) ($batch['defaultBarcodeType'] ?? (($capabilities['barcodeTypes'][0] ?? 'CODE128'))),
                    'recommendedBarcodeTypes' => array_values(array_map('strval', (array) ($batch['recommendedBarcodeTypes'] ?? []))),
                    'title' => (string) ($batch['title'] ?? ''),
                    'helperText' => (string) ($batch['helperText'] ?? ''),
                    'inputHint' => (string) ($batch['inputHint'] ?? ''),
                    'upcaHint' => (string) ($batch['upcaHint'] ?? ''),
                ],
            ];
        }

        return $result;
    }
}

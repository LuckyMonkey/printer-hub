<?php
declare(strict_types=1);

$env = static function (string $name, ?string $default = null): ?string {
    $value = getenv($name);
    if ($value === false || $value === '') {
        return $default;
    }

    return $value;
};

$envInt = static function (string $name, int $default) use ($env): int {
    $raw = $env($name);
    if ($raw === null || !is_numeric($raw)) {
        return $default;
    }

    return (int) $raw;
};

return [
    'rateLimitPerMinute' => $envInt('API_RATE_LIMIT_PER_MIN', 60),
    'errorHistoryLimit' => $envInt('PRINT_ERROR_HISTORY_LIMIT', 20),
    'socketTimeoutSeconds' => (float) ($env('PRINT_SOCKET_TIMEOUT_SEC', '4.0') ?? '4.0'),
    'logPath' => $env('PRINT_JOB_LOG_PATH', '/var/log/printer-hub/print-jobs.log'),
    'brotherMode' => strtolower($env('BROTHER_MODE', 'template') ?? 'template'),
    'printers' => [
        'zebra-zp505' => [
            'displayName' => 'Zebra ZP-505',
            'transport' => strtolower($env('ZEBRA_TRANSPORT', 'cups') ?? 'cups'),
            'host' => $env('ZEBRA_HOST', '127.0.0.1'),
            'port' => $envInt('ZEBRA_PORT', 9100),
            'cupsQueueName' => $env('PRINTER_ZEBRA_QUEUE', 'zebra_zp505'),
            'capabilities' => [
                'barcodeTypes' => ['CODE128', 'UPCA', 'QR'],
                'maxBarcodeLength' => 120,
                'maxTextLine1Length' => 60,
            ],
            'labelTypes' => [
                'waco-id' => [
                    'label' => 'Waco ID',
                    'description' => 'Code + text layout for inventory tags',
                ],
                'status-tag' => [
                    'label' => 'Status Tag',
                    'description' => 'Compact status barcode layout',
                ],
            ],
        ],
        'brother-ql820' => [
            'displayName' => 'Brother QL-820NWB',
            'transport' => strtolower($env('BROTHER_TRANSPORT', 'socket') ?? 'socket'),
            'host' => $env('BROTHER_HOST', '127.0.0.1'),
            'port' => $envInt('BROTHER_PORT', 9100),
            'cupsQueueName' => $env('PRINTER_BROTHER_QUEUE', 'brother_ql820nwb'),
            'capabilities' => [
                'barcodeTypes' => ['CODE128', 'UPCA', 'QR'],
                'maxBarcodeLength' => 120,
                'maxTextLine1Length' => 80,
            ],
            'labelTypes' => [
                'waco-id' => [
                    'label' => 'Waco ID',
                    'description' => 'Template 1: inventory barcode + text',
                ],
                'status-tag' => [
                    'label' => 'Status Tag',
                    'description' => 'Template 2: barcode + status text',
                ],
            ],
            'templateMap' => [
                'waco-id' => [
                    'templateId' => 1,
                    'objectMap' => [
                        'barcodeObjectIndex' => 1,
                        'textObjectIndex' => 2,
                    ],
                ],
                'status-tag' => [
                    'templateId' => 2,
                    'objectMap' => [
                        'barcodeObjectIndex' => 1,
                        'textObjectIndex' => 2,
                    ],
                ],
            ],
        ],
        'hp-envy-5055' => [
            'displayName' => 'HP Envy 5055',
            'transport' => 'cups',
            'cupsQueueName' => $env('PRINTER_HP_QUEUE', 'hp_envy_5055'),
            'capabilities' => [
                'barcodeTypes' => ['CODE128', 'UPCA', 'QR'],
                'maxBarcodeLength' => 120,
                'maxTextLine1Length' => 120,
            ],
            'labelTypes' => [
                'waco-id' => [
                    'label' => 'Waco ID',
                    'description' => 'Simple printable job card',
                ],
                'status-tag' => [
                    'label' => 'Status Tag',
                    'description' => 'Simple status card',
                ],
            ],
        ],
    ],
];

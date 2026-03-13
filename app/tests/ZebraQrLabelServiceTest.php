<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/CommandRunner.php';
require_once __DIR__ . '/../src/ZebraPngRasterService.php';
require_once __DIR__ . '/../src/ZebraQrLabelService.php';

use PrinterHub\CommandRunner;
use PrinterHub\ZebraPngRasterService;
use PrinterHub\ZebraQrLabelService;

if (!extension_loaded('gd')) {
    fwrite(STDERR, "GD extension is required\n");
    exit(1);
}

$commands = new CommandRunner();
$probe = $commands->run(['qrencode', '--help']);
if ($probe['code'] !== 0) {
    fwrite(STDERR, "qrencode is required for Zebra QR labels\n");
    exit(1);
}

$service = new ZebraQrLabelService($commands, new ZebraPngRasterService());

$businessCard = $service->buildZpl('business-card', 'https://fridge.local/?go=printers', 'Charlie');
if (strpos($businessCard, ':Z64:') === false || strpos($businessCard, '^GFA,') === false) {
    fwrite(STDERR, "Business card QR output should be rasterized ZPL\n");
    exit(1);
}

if (strpos($businessCard, '^BQN') !== false) {
    fwrite(STDERR, "Business card QR output should not use native ^BQN commands\n");
    exit(1);
}

$statusTag = $service->buildZpl('status-tag', 'https://fridge.local/services', 'Service Index');
if (strpos($statusTag, ':Z64:') === false) {
    fwrite(STDERR, "Status tag QR output should be rasterized ZPL\n");
    exit(1);
}

$failed = false;
try {
    $service->buildZpl('business-card', 'https://fridge.local', '');
} catch (RuntimeException) {
    $failed = true;
}

if (!$failed) {
    fwrite(STDERR, "Business card QR labels should require a name\n");
    exit(1);
}

echo "ZebraQrLabelServiceTest: OK\n";

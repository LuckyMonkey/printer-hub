<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/ZebraLabelService.php';

use PrinterHub\ZebraLabelService;

$service = new ZebraLabelService();

$zpl = $service->buildBatchGridZpl('waco-id', 'UPCA', ['03600029145']);
if (strpos($zpl, '^FD036000291452^FS') === false) {
    fwrite(STDERR, "Expected 11-digit UPC-A value to be normalized with check digit\n");
    exit(1);
}

$failed = false;
try {
    $service->buildBatchGridZpl('waco-id', 'UPCA', array_fill(0, 13, '036000291452'));
} catch (RuntimeException) {
    $failed = true;
}

if (!$failed) {
    fwrite(STDERR, "Expected Zebra batch grid to reject more than 12 values\n");
    exit(1);
}

echo "ZebraLabelServiceTest: OK\n";

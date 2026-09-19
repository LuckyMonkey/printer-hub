<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/ZebraLabelService.php';

use PrinterHub\ZebraLabelService;

$service = new ZebraLabelService();

// Code 128 carries the value verbatim. There is no normalisation step and no
// check digit to compute: unlike a retail symbology, the payload is whatever the
// operator assigned, which is the point for asset and inventory tagging.
$zpl = $service->buildBatchGridZpl('waco-id', 'CODE128', ['ASSET-0001']);
if (strpos($zpl, '^FDASSET-0001^FS') === false) {
    fwrite(STDERR, "Expected the Code 128 value to be emitted verbatim\n");
    exit(1);
}

if (strpos($zpl, '^BCN') === false) {
    fwrite(STDERR, "Expected a Code 128 (^BC) barcode in the batch grid\n");
    exit(1);
}

// The grid is 12-up; a thirteenth value has nowhere to go.
$failed = false;
try {
    $service->buildBatchGridZpl('waco-id', 'CODE128', array_fill(0, 13, 'ASSET-0001'));
} catch (RuntimeException) {
    $failed = true;
}

if (!$failed) {
    fwrite(STDERR, "Expected Zebra batch grid to reject more than 12 values\n");
    exit(1);
}

// UPC-A was removed deliberately: it cannot be self-assigned without a GS1
// manufacturer prefix, so it has no legitimate role in personal inventory
// tagging. Asking for it should fail loudly rather than silently fall back.
$rejected = false;
try {
    $service->buildBatchGridZpl('waco-id', 'UPCA', ['036000291452']);
} catch (RuntimeException) {
    $rejected = true;
}

if (!$rejected) {
    fwrite(STDERR, "Expected UPCA to be rejected as an unsupported barcode type\n");
    exit(1);
}

echo "ZebraLabelServiceTest: OK\n";

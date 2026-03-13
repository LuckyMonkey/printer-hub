<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/PrinterRegistry.php';

use PrinterHub\PrinterRegistry;

$registry = new PrinterRegistry(__DIR__ . '/../config/printers.php');
$public = $registry->listPublicConfig();

$byId = [];
foreach ($public as $printer) {
    $byId[(string) ($printer['printerId'] ?? '')] = $printer;
}

$zebra = $byId['zebra-zp505'] ?? null;
if (!is_array($zebra)) {
    fwrite(STDERR, "Zebra public config missing\n");
    exit(1);
}

$zebraBatch = $zebra['batch'] ?? null;
if (!is_array($zebraBatch)) {
    fwrite(STDERR, "Zebra batch config missing from public payload\n");
    exit(1);
}

if (($zebraBatch['enabled'] ?? false) !== true) {
    fwrite(STDERR, "Zebra batch should be enabled\n");
    exit(1);
}

if (($zebraBatch['chunkSize'] ?? null) !== 12) {
    fwrite(STDERR, "Zebra batch chunk size should be 12\n");
    exit(1);
}

if (($zebraBatch['defaultBarcodeType'] ?? null) !== 'UPCA') {
    fwrite(STDERR, "Zebra batch default barcode type should be UPCA\n");
    exit(1);
}

if (!in_array('UPCA', (array) ($zebraBatch['recommendedBarcodeTypes'] ?? []), true)) {
    fwrite(STDERR, "Zebra recommended batch types should include UPCA\n");
    exit(1);
}

$zebraLabelTypes = [];
foreach ((array) ($zebra['labelTypes'] ?? []) as $item) {
    $zebraLabelTypes[] = (string) ($item['id'] ?? '');
}

if (!in_array('business-card', $zebraLabelTypes, true)) {
    fwrite(STDERR, "Zebra label types should include business-card\n");
    exit(1);
}

$hp = $byId['hp-envy-5055'] ?? null;
if (!is_array($hp) || !is_array($hp['batch'] ?? null) || ($hp['batch']['chunkSize'] ?? null) !== 30) {
    fwrite(STDERR, "HP batch chunk size should be 30\n");
    exit(1);
}

echo "PrinterRegistryTest: OK\n";

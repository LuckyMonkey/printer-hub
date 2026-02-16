<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/BrotherTemplateClient.php';

use PrinterHub\BrotherTemplateClient;

$client = new BrotherTemplateClient();

$stream = $client->buildCommandStream(
    templateId: 1,
    objectDataByIndex: [
        1 => '051000568235',
        2 => 'Spaghettios',
    ],
    copies: 2
);

$hex = strtoupper(bin2hex($stream));

$expectedPrefix = '1B401B6961035E49495E54533030315E434E303032';
$expectedContains = [
    '5E4F5330315E4449', // ^OS01^DI
    '303531303030353638323335', // barcode payload
    '5E4F5330325E4449', // ^OS02^DI
    '5370616768657474696F73', // text payload
    '5E4646', // ^FF
];

if (!str_starts_with($hex, $expectedPrefix)) {
    fwrite(STDERR, "Brother snapshot prefix mismatch\n");
    fwrite(STDERR, "Actual: $hex\n");
    exit(1);
}

foreach ($expectedContains as $fragment) {
    if (!str_contains($hex, $fragment)) {
        fwrite(STDERR, "Brother snapshot missing fragment: $fragment\n");
        fwrite(STDERR, "Actual: $hex\n");
        exit(1);
    }
}

echo "BrotherTemplateClientSnapshotTest: OK\n";

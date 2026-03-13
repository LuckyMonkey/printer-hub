<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/ZebraPngRasterService.php';

use PrinterHub\ZebraPngRasterService;

if (!extension_loaded('gd')) {
    fwrite(STDERR, "GD extension is required\n");
    exit(1);
}

$tmpPng = tempnam(sys_get_temp_dir(), 'zebra_png_test_');
if ($tmpPng === false) {
    fwrite(STDERR, "Unable to allocate temp file\n");
    exit(1);
}

$pngPath = $tmpPng . '.png';
rename($tmpPng, $pngPath);

$image = imagecreatetruecolor(40, 60);
$white = imagecolorallocate($image, 255, 255, 255);
$black = imagecolorallocate($image, 0, 0, 0);
imagefilledrectangle($image, 0, 0, 39, 59, $white);
imagefilledrectangle($image, 10, 12, 29, 47, $black);
imagepng($image, $pngPath);
imagedestroy($image);

$service = new ZebraPngRasterService();
$zpl = $service->buildZplFromPngPath($pngPath, 160);
@unlink($pngPath);

if (!preg_match('/^\\^XA.*:Z64:([^:]+):([A-F0-9]{4}).*\\^XZ\\s*$/s', $zpl, $m)) {
    fwrite(STDERR, "Failed to locate Z64 payload in generated ZPL\n");
    exit(1);
}

$compressed = base64_decode($m[1], true);
if ($compressed === false) {
    fwrite(STDERR, "Failed to decode Z64 payload\n");
    exit(1);
}

$binary = gzuncompress($compressed);
if ($binary === false) {
    fwrite(STDERR, "Failed to decompress Z64 payload\n");
    exit(1);
}

$totalBytes = strlen($binary);
$nonZeroBytes = strlen(str_replace("\x00", '', $binary));

if ($nonZeroBytes === 0 || $nonZeroBytes === $totalBytes) {
    fwrite(STDERR, "Raster output collapsed to a single tone\n");
    exit(1);
}

echo "ZebraPngRasterServiceTest: OK\n";

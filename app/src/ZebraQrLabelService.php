<?php
declare(strict_types=1);

namespace PrinterHub;

use GdImage;
use RuntimeException;

final class ZebraQrLabelService
{
    private const LABEL_WIDTH = 812;
    private const LABEL_HEIGHT = 1218;

    public function __construct(
        private readonly CommandRunner $commands,
        private readonly ZebraPngRasterService $raster
    ) {
    }

    public function buildZpl(string $labelType, string $qrValue, string $textLine1): string
    {
        $safeLabelType = strtolower(trim($labelType));
        $safeValue = $this->sanitize($qrValue);
        $safeText = $this->sanitize($textLine1);

        if ($safeValue === '') {
            throw new RuntimeException('QR barcodeValue is required.');
        }

        if ($safeLabelType === 'business-card' && $safeText === '') {
            throw new RuntimeException('Business card Zebra QR labels require textLine1 for the name.');
        }

        $pngPath = $this->renderTemplate($safeLabelType, $safeValue, $safeText);
        try {
            return $this->raster->buildZplFromPngPath($pngPath);
        } finally {
            @unlink($pngPath);
        }
    }

    private function renderTemplate(string $labelType, string $qrValue, string $textLine1): string
    {
        if (!extension_loaded('gd')) {
            throw new RuntimeException('PHP GD extension is required for Zebra QR rendering.');
        }

        $canvas = imagecreatetruecolor(self::LABEL_WIDTH, self::LABEL_HEIGHT);
        if (!$canvas instanceof GdImage) {
            throw new RuntimeException('Unable to allocate Zebra QR canvas.');
        }

        $white = imagecolorallocate($canvas, 255, 255, 255);
        $black = imagecolorallocate($canvas, 0, 0, 0);
        $gray = imagecolorallocate($canvas, 85, 85, 85);
        imagefilledrectangle($canvas, 0, 0, self::LABEL_WIDTH - 1, self::LABEL_HEIGHT - 1, $white);

        $qrPath = $this->generateQrPng($qrValue, $labelType === 'business-card' ? 11 : 9);
        $qr = @imagecreatefrompng($qrPath);
        if (!$qr instanceof GdImage) {
            @unlink($qrPath);
            imagedestroy($canvas);
            throw new RuntimeException('Unable to load generated QR PNG.');
        }

        try {
            match ($labelType) {
                'business-card' => $this->drawBusinessCard($canvas, $qr, $textLine1, $qrValue, $black, $gray),
                'status-tag' => $this->drawStatusTag($canvas, $qr, $textLine1, $qrValue, $black, $gray),
                default => $this->drawWacoQr($canvas, $qr, $textLine1, $qrValue, $black, $gray),
            };
        } finally {
            imagedestroy($qr);
            @unlink($qrPath);
        }

        $tmpDir = $this->ensureTempDir();
        $pngPath = sprintf('%s/%s.png', $tmpDir, uniqid('zebra_qr_', true));
        if (!imagepng($canvas, $pngPath)) {
            imagedestroy($canvas);
            throw new RuntimeException('Unable to write temporary Zebra QR PNG.');
        }

        imagedestroy($canvas);
        return $pngPath;
    }

    private function drawBusinessCard(GdImage $canvas, GdImage $qr, string $name, string $url, int $black, int $gray): void
    {
        imagerectangle($canvas, 34, 34, 778, 1184, $black);
        imagerectangle($canvas, 42, 42, 770, 1176, $black);

        $this->drawTextBlock($canvas, 'QR BUSINESS CARD', 62, 106, 22, $black, 1, false);
        $this->drawTextBlock($canvas, $name, 62, 198, 44, $black, 2, true, 652);

        imageline($canvas, 62, 252, 748, 252, $gray);

        $this->placeQr($canvas, $qr, 418, 330, 300, 300);
        imagerectangle($canvas, 406, 318, 730, 642, $black);

        $this->drawTextBlock($canvas, 'Scan to open link', 430, 696, 20, $gray, 1, false);
        $this->drawTextBlock($canvas, $url, 62, 356, 24, $black, 6, false, 306);
        $this->drawTextBlock($canvas, 'Use the QR code or type the URL.', 62, 828, 18, $gray, 1, false);
    }

    private function drawWacoQr(GdImage $canvas, GdImage $qr, string $textLine1, string $url, int $black, int $gray): void
    {
        $this->drawTextBlock($canvas, 'Waco ID QR', 56, 96, 36, $black, 1, true);
        if ($textLine1 !== '') {
            $this->drawTextBlock($canvas, $textLine1, 56, 150, 26, $gray, 2, false, 700);
        }

        $this->placeQr($canvas, $qr, 80, 250, 320, 320);
        imagerectangle($canvas, 68, 238, 412, 582, $black);

        $this->drawTextBlock($canvas, 'Scan', 470, 292, 28, $black, 1, true);
        $this->drawTextBlock($canvas, $url, 470, 350, 24, $black, 7, false, 260);
    }

    private function drawStatusTag(GdImage $canvas, GdImage $qr, string $textLine1, string $url, int $black, int $gray): void
    {
        $this->drawTextBlock($canvas, 'Status Tag QR', 48, 92, 32, $black, 1, true);
        $this->drawTextBlock($canvas, $textLine1 !== '' ? $textLine1 : 'Scan for details', 48, 144, 24, $gray, 2, false, 710);

        $this->placeQr($canvas, $qr, 92, 250, 280, 280);
        imagerectangle($canvas, 80, 238, 384, 542, $black);

        $this->drawTextBlock($canvas, $url, 430, 286, 22, $black, 8, false, 300);
        $this->drawTextBlock($canvas, 'QR is rasterized for reliable Zebra output.', 48, 988, 18, $gray, 2, false, 700);
    }

    private function placeQr(GdImage $canvas, GdImage $qr, int $x, int $y, int $targetWidth, int $targetHeight): void
    {
        $sourceWidth = imagesx($qr);
        $sourceHeight = imagesy($qr);
        imagecopyresampled(
            $canvas,
            $qr,
            $x,
            $y,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $sourceWidth,
            $sourceHeight
        );
    }

    private function drawTextBlock(
        GdImage $canvas,
        string $text,
        int $x,
        int $y,
        int $size,
        int $color,
        int $maxLines,
        bool $bold = false,
        ?int $maxWidth = null
    ): void {
        $value = trim($text);
        if ($value === '') {
            return;
        }

        $font = $this->resolveFontPath();
        if ($font === null || !function_exists('imagettftext')) {
            $this->drawFallbackText($canvas, $value, $x, $y, $color, $maxLines);
            return;
        }

        $width = $maxWidth ?? (self::LABEL_WIDTH - $x - 56);
        $lines = $this->wrapText($value, $font, $size, $width, $maxLines);
        $lineHeight = (int) round($size * 1.35);

        foreach ($lines as $index => $line) {
            $baseline = $y + ($index * $lineHeight);
            imagettftext($canvas, $size, 0, $x, $baseline, $color, $font, $line);
            if ($bold) {
                imagettftext($canvas, $size, 0, $x + 1, $baseline, $color, $font, $line);
            }
        }
    }

    private function drawFallbackText(GdImage $canvas, string $text, int $x, int $y, int $color, int $maxLines): void
    {
        $chunks = str_split(substr($text, 0, 120), 32);
        foreach (array_slice($chunks, 0, $maxLines) as $index => $line) {
            imagestring($canvas, 5, $x, $y + ($index * 24), $line, $color);
        }
    }

    /**
     * @return list<string>
     */
    private function wrapText(string $text, string $fontPath, int $size, int $maxWidth, int $maxLines): array
    {
        $words = preg_split('/\s+/', $text) ?: [];
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current . ' ' . $word;
            if ($this->textWidth($candidate, $fontPath, $size) <= $maxWidth) {
                $current = $candidate;
                continue;
            }

            if ($current !== '') {
                $lines[] = $current;
                if (count($lines) >= $maxLines) {
                    return $this->truncateLines($lines, $fontPath, $size, $maxWidth);
                }
            }

            $current = $word;
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        return $this->truncateLines(array_slice($lines, 0, $maxLines), $fontPath, $size, $maxWidth);
    }

    /**
     * @param list<string> $lines
     * @return list<string>
     */
    private function truncateLines(array $lines, string $fontPath, int $size, int $maxWidth): array
    {
        if ($lines === []) {
            return [];
        }

        $lastIndex = count($lines) - 1;
        if ($this->textWidth($lines[$lastIndex], $fontPath, $size) <= $maxWidth) {
            return $lines;
        }

        $line = rtrim($lines[$lastIndex], ". \t");
        while (strlen($line) > 1 && $this->textWidth($line . '...', $fontPath, $size) > $maxWidth) {
            $line = rtrim(substr($line, 0, -1), ". \t");
        }
        $lines[$lastIndex] = $line . '...';

        return $lines;
    }

    private function textWidth(string $text, string $fontPath, int $size): int
    {
        $box = imagettfbbox($size, 0, $fontPath, $text);
        if (!is_array($box)) {
            return 0;
        }

        return (int) abs($box[2] - $box[0]);
    }

    private function resolveFontPath(): ?string
    {
        foreach ([
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/truetype/liberation2/LiberationSans-Regular.ttf',
        ] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function generateQrPng(string $value, int $moduleSize): string
    {
        $tmpDir = $this->ensureTempDir();
        $qrPath = sprintf('%s/%s.png', $tmpDir, uniqid('zebra_qr_src_', true));

        $result = $this->commands->run([
            'qrencode',
            '-o', $qrPath,
            '-s', (string) max(3, min($moduleSize, 16)),
            '-m', '2',
            $value,
        ]);

        if ($result['code'] !== 0 || !is_file($qrPath)) {
            throw new RuntimeException(
                $result['stderr'] !== ''
                    ? sprintf('QR rendering failed: %s', $result['stderr'])
                    : 'QR rendering failed. Ensure qrencode is installed in the container.'
            );
        }

        return $qrPath;
    }

    private function ensureTempDir(): string
    {
        $tmpDir = '/tmp/printer-hub';
        if (!is_dir($tmpDir) && !mkdir($tmpDir, 0775, true) && !is_dir($tmpDir)) {
            throw new RuntimeException('Unable to create /tmp/printer-hub for Zebra QR rendering.');
        }

        return $tmpDir;
    }

    private function sanitize(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/[\x00-\x1F\x7F]/', '', $value) ?? '';
        return str_replace(['^', '~'], ' ', $value);
    }
}

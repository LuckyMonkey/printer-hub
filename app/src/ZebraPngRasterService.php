<?php
declare(strict_types=1);

namespace PrinterHub;

use GdImage;
use RuntimeException;

final class ZebraPngRasterService
{
    private const LABEL_WIDTH = 812;
    private const LABEL_HEIGHT = 1218;

    public function buildZplFromPngPath(string $pngPath, int $threshold = 160): string
    {
        $threshold = max(1, min($threshold, 254));

        if (!extension_loaded('gd')) {
            throw new RuntimeException('PHP GD extension is required for Zebra PNG rendering.');
        }

        if (!is_file($pngPath)) {
            throw new RuntimeException(sprintf('PNG file does not exist: %s', $pngPath));
        }

        $source = @imagecreatefrompng($pngPath);
        if (!$source instanceof GdImage) {
            throw new RuntimeException(sprintf('Unable to load PNG file: %s', $pngPath));
        }

        $canvas = imagecreatetruecolor(self::LABEL_WIDTH, self::LABEL_HEIGHT);
        if (!$canvas instanceof GdImage) {
            imagedestroy($source);
            throw new RuntimeException('Unable to allocate Zebra raster canvas.');
        }

        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, self::LABEL_WIDTH - 1, self::LABEL_HEIGHT - 1, $white);

        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $scale = min(self::LABEL_WIDTH / $sourceWidth, self::LABEL_HEIGHT / $sourceHeight);
        $targetWidth = max(1, (int) round($sourceWidth * $scale));
        $targetHeight = max(1, (int) round($sourceHeight * $scale));
        $targetX = (int) floor((self::LABEL_WIDTH - $targetWidth) / 2);
        $targetY = (int) floor((self::LABEL_HEIGHT - $targetHeight) / 2);

        imagecopyresampled(
            $canvas,
            $source,
            $targetX,
            $targetY,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $sourceWidth,
            $sourceHeight
        );
        imagedestroy($source);

        $mono = imagecreate(self::LABEL_WIDTH, self::LABEL_HEIGHT);
        if (!$mono instanceof GdImage) {
            imagedestroy($canvas);
            throw new RuntimeException('Unable to allocate Zebra monochrome canvas.');
        }

        $monoWhite = imagecolorallocate($mono, 255, 255, 255);
        $monoBlack = imagecolorallocate($mono, 0, 0, 0);
        imagefilledrectangle($mono, 0, 0, self::LABEL_WIDTH - 1, self::LABEL_HEIGHT - 1, $monoWhite);

        for ($y = 0; $y < self::LABEL_HEIGHT; $y++) {
            for ($x = 0; $x < self::LABEL_WIDTH; $x++) {
                $rgb = imagecolorat($canvas, $x, $y);
                $red = ($rgb >> 16) & 0xFF;
                $green = ($rgb >> 8) & 0xFF;
                $blue = $rgb & 0xFF;
                $gray = (int) round(($red * 0.299) + ($green * 0.587) + ($blue * 0.114));

                imagesetpixel($mono, $x, $y, $gray < $threshold ? $monoBlack : $monoWhite);
            }
        }

        imagedestroy($canvas);

        $bytesPerRow = (int) ceil(self::LABEL_WIDTH / 8);
        $binary = '';

        for ($y = 0; $y < self::LABEL_HEIGHT; $y++) {
            for ($byteOffset = 0; $byteOffset < $bytesPerRow; $byteOffset++) {
                $byte = 0;
                for ($bit = 0; $bit < 8; $bit++) {
                    $byte <<= 1;
                    $x = ($byteOffset * 8) + $bit;
                    if ($x < self::LABEL_WIDTH && imagecolorat($mono, $x, $y) === $monoBlack) {
                        $byte |= 1;
                    }
                }
                $binary .= chr($byte);
            }
        }

        imagedestroy($mono);

        $encoded = $this->encodeZ64($binary);

        return implode("\n", [
            '^XA',
            '^CI28',
            '^PW812',
            '^LL1218',
            '^LH0,0',
            sprintf(
                '^FO0,0^GFA,%d,%d,%d,:Z64:%s:%s',
                strlen($binary),
                strlen($binary),
                $bytesPerRow,
                $encoded['data'],
                $encoded['crc']
            ),
            '^XZ',
        ]) . "\n";
    }

    /**
     * @return array{data:string,crc:string}
     */
    private function encodeZ64(string $binary): array
    {
        $compressed = gzcompress($binary, 9);
        if ($compressed === false) {
            throw new RuntimeException('Unable to compress Zebra image payload.');
        }

        $data = base64_encode($compressed);
        $crc = strtoupper(str_pad(dechex($this->crc16Ccitt($data)), 4, '0', STR_PAD_LEFT));

        return [
            'data' => $data,
            'crc' => $crc,
        ];
    }

    private function crc16Ccitt(string $payload): int
    {
        $crc = 0x0000;
        $length = strlen($payload);

        for ($i = 0; $i < $length; $i++) {
            $crc ^= (ord($payload[$i]) << 8);
            for ($bit = 0; $bit < 8; $bit++) {
                if (($crc & 0x8000) !== 0) {
                    $crc = (($crc << 1) ^ 0x1021) & 0xFFFF;
                } else {
                    $crc = ($crc << 1) & 0xFFFF;
                }
            }
        }

        return $crc;
    }
}

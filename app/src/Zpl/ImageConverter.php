<?php

declare(strict_types=1);

namespace PrinterHub\Zpl;

use GdImage;
use RuntimeException;

/**
 * Turns a PNG/JPEG/GIF into a placeable ^GF graphic field.
 *
 * Differs from ZebraPngRasterService, which composes a whole 812x1218 label:
 * this produces just the field, at whatever size you ask for, so the editor can
 * drop an image anywhere on a label of any size.
 *
 * Thermal printers are one bit per dot - there is no grey. The interesting
 * choice is therefore how to get from a photograph to black and white:
 *
 *   threshold   every pixel darker than the cut becomes black. Crisp for logos,
 *               line art and text; destroys a photograph.
 *   dither      Floyd-Steinberg error diffusion. Photographs survive, at the
 *               cost of a stipple that looks like noise on flat artwork.
 *
 * Labelary only thresholds. Dithering is the reason a photo can be printed on a
 * label at all, so it is offered here.
 */
final class ImageConverter
{
    /** Guard rail: a label is a few hundred dots, not a wall poster. */
    private const MAX_DIMENSION = 4096;

    /**
     * @return array{zpl:string, width:int, height:int, bytes:int}
     */
    public function toGraphicField(
        string $imageData,
        ?int $targetWidth = null,
        int $threshold = 128,
        bool $dither = false,
    ): array {
        if (!extension_loaded('gd')) {
            throw new RuntimeException('PHP GD is required to convert images.');
        }

        $src = @imagecreatefromstring($imageData);
        if (!$src instanceof GdImage) {
            throw new RuntimeException('That file is not a PNG, JPEG or GIF this server can read.');
        }

        $srcW = imagesx($src);
        $srcH = imagesy($src);

        $width = $targetWidth ?? $srcW;
        if ($width < 1 || $width > self::MAX_DIMENSION) {
            imagedestroy($src);

            throw new RuntimeException('Target width must be between 1 and ' . self::MAX_DIMENSION . ' dots.');
        }
        $height = max(1, (int) round($srcH * ($width / $srcW)));
        if ($height > self::MAX_DIMENSION) {
            imagedestroy($src);

            throw new RuntimeException('Resulting height exceeds ' . self::MAX_DIMENSION . ' dots.');
        }

        $scaled = imagecreatetruecolor($width, $height);
        if (!$scaled instanceof GdImage) {
            imagedestroy($src);

            throw new RuntimeException('Unable to allocate the scaled image.');
        }
        // Flatten onto white first: a transparent PNG otherwise composites onto
        // black and arrives as a solid rectangle of ink.
        $white = (int) imagecolorallocate($scaled, 255, 255, 255);
        imagefilledrectangle($scaled, 0, 0, $width - 1, $height - 1, $white);
        imagealphablending($scaled, true);
        imagecopyresampled($scaled, $src, 0, 0, 0, 0, $width, $height, $srcW, $srcH);
        imagedestroy($src);

        // Luminance once, then either cut or diffuse.
        //
        // Alpha is composited onto white BY HAND rather than trusting GD to have
        // blended during the resample. imagecopyresampled can carry the alpha
        // channel through untouched, and a fully transparent PNG is usually
        // transparent BLACK - so reading the raw RGB turns an empty image into a
        // solid block of ink. That is a spectacular way to waste a roll of labels.
        $grey = [];
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $rgba = imagecolorat($scaled, $x, $y);
                $alpha = ($rgba >> 24) & 0x7F;            // 0 = opaque, 127 = clear
                $opacity = (127 - $alpha) / 127;
                $r = (($rgba >> 16) & 0xFF) * $opacity + 255 * (1 - $opacity);
                $g = (($rgba >> 8) & 0xFF) * $opacity + 255 * (1 - $opacity);
                $b = ($rgba & 0xFF) * $opacity + 255 * (1 - $opacity);
                $grey[$y][$x] = (0.299 * $r) + (0.587 * $g) + (0.114 * $b);
            }
        }
        imagedestroy($scaled);

        $bits = $dither
            ? $this->floydSteinberg($grey, $width, $height)
            : $this->threshold($grey, $width, $height, $threshold);

        $bytesPerRow = (int) ceil($width / 8);
        $binary = '';
        for ($y = 0; $y < $height; $y++) {
            for ($b = 0; $b < $bytesPerRow; $b++) {
                $byte = 0;
                for ($bit = 0; $bit < 8; $bit++) {
                    $byte <<= 1;
                    $x = $b * 8 + $bit;
                    // Pixels past the image width are padding and stay white.
                    if ($x < $width && $bits[$y][$x]) {
                        $byte |= 1;
                    }
                }
                $binary .= chr($byte);
            }
        }

        $total = strlen($binary);
        $encoded = $this->encodeZ64($binary);

        return [
            'zpl' => sprintf('^GFA,%d,%d,%d,:Z64:%s:%s', $total, $total, $bytesPerRow, $encoded['data'], $encoded['crc']),
            'width' => $width,
            'height' => $height,
            'bytes' => $total,
        ];
    }

    /**
     * @param array<int,array<int,float>> $grey
     *
     * @return array<int,array<int,bool>>
     */
    private function threshold(array $grey, int $w, int $h, int $threshold): array
    {
        $threshold = max(1, min(254, $threshold));
        $out = [];
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $out[$y][$x] = $grey[$y][$x] < $threshold;
            }
        }

        return $out;
    }

    /**
     * Floyd-Steinberg error diffusion: each pixel is pushed to black or white
     * and the rounding error is spread into neighbours not yet visited, so
     * large flat areas average out to the original tone.
     *
     * @param array<int,array<int,float>> $grey
     *
     * @return array<int,array<int,bool>>
     */
    private function floydSteinberg(array $grey, int $w, int $h): array
    {
        $out = [];
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $old = $grey[$y][$x];
                $new = $old < 128 ? 0.0 : 255.0;
                $out[$y][$x] = $new === 0.0;
                $err = $old - $new;

                if ($x + 1 < $w) {
                    $grey[$y][$x + 1] += $err * 7 / 16;
                }
                if ($y + 1 < $h) {
                    if ($x > 0) {
                        $grey[$y + 1][$x - 1] += $err * 3 / 16;
                    }
                    $grey[$y + 1][$x] += $err * 5 / 16;
                    if ($x + 1 < $w) {
                        $grey[$y + 1][$x + 1] += $err * 1 / 16;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * @return array{data:string,crc:string}
     */
    private function encodeZ64(string $binary): array
    {
        $compressed = gzcompress($binary, 9);
        if ($compressed === false) {
            throw new RuntimeException('Unable to compress the image payload.');
        }
        $data = base64_encode($compressed);

        return [
            'data' => $data,
            // The CRC covers the base64 TEXT, not the bytes it represents.
            'crc' => strtoupper(str_pad(dechex($this->crc16Ccitt($data)), 4, '0', STR_PAD_LEFT)),
        ];
    }

    private function crc16Ccitt(string $payload): int
    {
        $crc = 0x0000;
        for ($i = 0, $n = strlen($payload); $i < $n; $i++) {
            $crc ^= ord($payload[$i]) << 8;
            for ($bit = 0; $bit < 8; $bit++) {
                $crc = ($crc & 0x8000) !== 0 ? (($crc << 1) ^ 0x1021) : ($crc << 1);
                $crc &= 0xFFFF;
            }
        }

        return $crc;
    }
}

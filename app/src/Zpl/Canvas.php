<?php

declare(strict_types=1);

namespace PrinterHub\Zpl;

use GdImage;
use RuntimeException;

/**
 * A label-sized raster in printer dots.
 *
 * Thermal printers are monochrome and think in dots, not pixels or points, so
 * everything here is integer dots at a given density (8 dots/mm = 203 dpi is the
 * common Zebra default). Keeping the canvas in dots means a preview is a literal
 * 1:1 map of what the printer will burn, which is the whole point of a preview.
 */
final class Canvas
{
    private GdImage $im;
    private int $black;
    private int $white;

    public function __construct(
        public readonly int $width,
        public readonly int $height,
    ) {
        if ($width < 1 || $height < 1 || $width > 10000 || $height > 10000) {
            throw new RuntimeException('Label dimensions out of range.');
        }
        $im = imagecreatetruecolor($width, $height);
        if (!$im instanceof GdImage) {
            throw new RuntimeException('Unable to allocate label canvas.');
        }
        $this->im = $im;
        $this->white = (int) imagecolorallocate($im, 255, 255, 255);
        $this->black = (int) imagecolorallocate($im, 0, 0, 0);
        imagefilledrectangle($im, 0, 0, $width - 1, $height - 1, $this->white);
    }

    public function image(): GdImage
    {
        return $this->im;
    }

    public function fill(int $x, int $y, int $w, int $h, bool $inverse = false): void
    {
        if ($w <= 0 || $h <= 0) {
            return;
        }
        imagefilledrectangle($this->im, $x, $y, $x + $w - 1, $y + $h - 1, $inverse ? $this->white : $this->black);
    }

    /** Outline box with a given border thickness, drawn inward like ZPL's ^GB. */
    public function box(int $x, int $y, int $w, int $h, int $thickness, bool $inverse = false): void
    {
        $thickness = max(1, $thickness);
        if ($thickness * 2 >= $w || $thickness * 2 >= $h) {
            $this->fill($x, $y, $w, $h, $inverse);

            return;
        }
        $this->fill($x, $y, $w, $thickness, $inverse);
        $this->fill($x, $y + $h - $thickness, $w, $thickness, $inverse);
        $this->fill($x, $y, $thickness, $h, $inverse);
        $this->fill($x + $w - $thickness, $y, $thickness, $h, $inverse);
    }

    public function png(): string
    {
        ob_start();
        imagepng($this->im, null, 6);
        $out = (string) ob_get_clean();
        if ($out === '') {
            throw new RuntimeException('Failed to encode label PNG.');
        }

        return $out;
    }

    public function __destruct()
    {
        if (isset($this->im)) {
            imagedestroy($this->im);
        }
    }
}

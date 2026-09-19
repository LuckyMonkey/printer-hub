<?php

declare(strict_types=1);

namespace PrinterHub\Zpl;

use PrinterHub\Zpl\Barcode\Code128;
use PrinterHub\Zpl\Barcode\Code39;
use PrinterHub\Zpl\Barcode\QrCode;
use RuntimeException;

/**
 * Interprets a ZPL label and rasterises it.
 *
 * ZPL is a field-oriented language: commands accumulate state (where, what font,
 * what symbology) and a field is only committed when ^FS closes it - or when the
 * next ^FO/^FT implicitly starts another one, which real-world labels rely on
 * more than the manual admits. Everything is in printer dots, so the output is a
 * 1:1 map of what the printer will burn rather than an approximation of it.
 *
 * Unsupported commands are collected in warnings() rather than throwing: a
 * preview that renders most of a label and tells you what it skipped is far more
 * useful than one that refuses the whole thing over a ^PQ.
 */
final class Renderer
{
    private Canvas $canvas;
    private Code128 $code128;
    private Code39 $code39;

    /** @var list<string> */
    private array $warnings = [];

    // --- label state ---------------------------------------------------------
    private int $homeX = 0;
    private int $homeY = 0;

    // --- default font (^CF) --------------------------------------------------
    private int $defaultFontHeight = 20;
    private int $defaultFontWidth = 0;

    // --- barcode defaults (^BY) ---------------------------------------------
    private int $moduleWidth = 2;
    private int $barRatio = 3;
    private int $barHeight = 60;

    // --- pending field -------------------------------------------------------
    private ?int $fieldX = null;
    private ?int $fieldY = null;
    private bool $fieldBaseline = false;
    private bool $fieldReverse = false;
    private ?int $fontHeight = null;
    private ?int $fontWidth = null;
    /** @var array{type:string,height:int,line:bool,lineAbove:bool}|null */
    private ?array $pendingBarcode = null;
    private ?string $fieldData = null;

    public function __construct(
        private readonly int $widthDots,
        private readonly int $heightDots,
        private readonly ?string $fontPath = null,
    ) {
        $this->canvas = new Canvas($widthDots, $heightDots);
        $this->code128 = new Code128();
        $this->code39 = new Code39();
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return array_values(array_unique($this->warnings));
    }

    public function render(string $zpl): string
    {
        foreach ((new Lexer())->tokenize($zpl) as $cmd) {
            $this->apply($cmd);
        }
        // A label that never closed its last field should still show it.
        $this->commitField();

        return $this->canvas->png();
    }

    private function apply(Command $c): void
    {
        switch ($c->name) {
            case 'XA':                       // start of label
                $this->resetField();
                break;

            case 'XZ':                       // end of label
                $this->commitField();
                break;

            case 'LH':                       // label home offset
                $this->homeX = $c->int(0, 0);
                $this->homeY = $c->int(1, 0);
                break;

            case 'FO':                       // field origin (top-left)
            case 'FT':                       // field typeset (baseline)
                // An un-closed field is committed here: labels in the wild often
                // omit ^FS between fields and printers accept it.
                $this->commitField();
                $this->fieldX = $c->int(0, 0);
                $this->fieldY = $c->int(1, 0);
                $this->fieldBaseline = $c->name === 'FT';
                break;

            case 'A':                        // scalable font for this field
                $this->fontHeight = $c->int(1, $this->defaultFontHeight);
                $this->fontWidth = $c->int(2, 0);
                break;

            case 'CF':                       // change default font
                $this->defaultFontHeight = $c->int(1, $this->defaultFontHeight);
                $this->defaultFontWidth = $c->int(2, 0);
                break;

            case 'BY':                       // barcode defaults
                $this->moduleWidth = max(1, $c->int(0, $this->moduleWidth));
                $r = (int) round((float) ($c->params[1] ?? $this->barRatio));
                $this->barRatio = max(2, min(3, $r ?: $this->barRatio));
                $this->barHeight = max(1, $c->int(2, $this->barHeight));
                break;

            case 'BC':                       // Code 128
                $this->pendingBarcode = [
                    'type' => 'code128',
                    'height' => $c->int(1, $this->barHeight),
                    'line' => $c->str(2, 'Y') === 'Y',
                    'lineAbove' => $c->str(3, 'N') === 'Y',
                ];
                break;

            case 'B3':                       // Code 39
                $this->pendingBarcode = [
                    'type' => 'code39',
                    'height' => $c->int(2, $this->barHeight),
                    'line' => $c->str(3, 'Y') === 'Y',
                    'lineAbove' => $c->str(4, 'N') === 'Y',
                ];
                break;

            case 'BQ':                       // QR code
                // ^BQa,b,c - c is the magnification, i.e. dots per module.
                $this->pendingBarcode = [
                    'type' => 'qr',
                    'height' => max(1, $c->int(2, 3)),   // reused as magnification
                    'line' => false,
                    'lineAbove' => false,
                ];
                break;

            case 'FR':                       // reverse this field
                $this->fieldReverse = true;
                break;

            case 'FD':                       // field data
                $this->fieldData = $c->raw;
                break;

            case 'FS':                       // field separator - commit
                $this->commitField();
                break;

            case 'GB':                       // graphic box
                $this->drawBox($c);
                break;

            case 'FX':                       // comment
            case 'CI':                       // character set
            case 'PQ':                       // print quantity
            case 'PR':                       // print rate
            case 'MD':                       // media darkness
            case 'MN':                       // media tracking
            case 'MT':                       // media type
            case 'LS':                       // label shift
            case 'PO':                       // print orientation
            case 'JM':                       // set dots per mm
            case 'SE':                       // select encoding
                break;                       // no visual effect on a preview

            case 'PW':                       // print width - fixed by the caller
            case 'LL':                       // label length - fixed by the caller
                break;

            default:
                $this->warnings[] = $c->prefix . $c->name;
        }
    }

    private function resetField(): void
    {
        $this->fieldX = null;
        $this->fieldY = null;
        $this->fieldBaseline = false;
        $this->fieldReverse = false;
        $this->fontHeight = null;
        $this->fontWidth = null;
        $this->pendingBarcode = null;
        $this->fieldData = null;
    }

    private function commitField(): void
    {
        $data = $this->fieldData;
        if ($data === null || $data === '' || $this->fieldX === null || $this->fieldY === null) {
            $this->resetField();

            return;
        }

        $x = $this->fieldX + $this->homeX;
        $y = $this->fieldY + $this->homeY;

        if ($this->pendingBarcode !== null) {
            $this->drawBarcode($x, $y, $data, $this->pendingBarcode);
        } else {
            $h = $this->fontHeight ?? $this->defaultFontHeight;
            // ^FT positions the BASELINE; ^FO positions the top-left corner.
            $top = $this->fieldBaseline ? $y - $h : $y;
            $this->drawText($x, $top, $data, $h, $this->fieldReverse);
        }

        $this->resetField();
    }

    /** @param array{type:string,height:int,line:bool,lineAbove:bool} $bc */
    private function drawBarcode(int $x, int $y, string $data, array $bc): void
    {
        if ($bc['type'] === 'qr') {
            $this->drawQr($x, $y, $data, $bc['height']);

            return;
        }

        $runs = $bc['type'] === 'code39'
            ? $this->code39->encode($data, $this->barRatio)
            : $this->code128->encode($data);

        $height = max(1, $bc['height']);
        $module = $this->moduleWidth;

        // Human-readable line sits below the bars by default, above with ^BCx,,,Y.
        $textHeight = $bc['line'] ? (int) round($height * 0.18) + 6 : 0;
        $barsTop = $y + ($bc['lineAbove'] && $bc['line'] ? $textHeight : 0);

        $cursor = $x;
        $isBar = true;                       // every symbology here starts on a bar
        foreach ($runs as $run) {
            $w = $run * $module;
            if ($isBar) {
                $this->canvas->fill($cursor, $barsTop, $w, $height);
            }
            $cursor += $w;
            $isBar = !$isBar;
        }

        if ($bc['line']) {
            $label = $bc['type'] === 'code39' ? '*' . strtoupper($data) . '*' : $data;
            $ty = $bc['lineAbove'] ? $y : $barsTop + $height + 2;
            $this->drawText($x, $ty, $label, max(12, $textHeight - 2), false);
        }
    }

    /**
     * ^BQ carries its error-correction level and input mode INSIDE the field
     * data, not as command parameters: ^FDLA,PAYLOAD means level L, automatic
     * input. That prefix is Zebra's, not part of the payload, so it must be
     * stripped before encoding or every code carries two stray characters.
     */
    private function drawQr(int $x, int $y, string $data, int $magnification): void
    {
        $ec = QrCode::EC_M;
        $payload = $data;

        if (preg_match('/^([LMQH])([AMNK])?,(.*)$/s', $data, $m) === 1) {
            $ec = match ($m[1]) {
                'L' => QrCode::EC_L,
                'M' => QrCode::EC_M,
                'Q' => QrCode::EC_Q,
                'H' => QrCode::EC_H,
            };
            $payload = $m[3];
        }

        if ($payload === '') {
            $this->warnings[] = '^BQ with empty payload';

            return;
        }

        try {
            $matrix = (new QrCode())->encode($payload, $ec);
        } catch (RuntimeException $e) {
            $this->warnings[] = '^BQ: ' . $e->getMessage();

            return;
        }

        $module = max(1, $magnification);
        foreach ($matrix as $r => $row) {
            foreach ($row as $c => $dark) {
                if ($dark) {
                    $this->canvas->fill($x + $c * $module, $y + $r * $module, $module, $module);
                }
            }
        }
    }

    private function drawBox(Command $c): void
    {
        if ($this->fieldX === null || $this->fieldY === null) {
            $this->warnings[] = '^GB without ^FO';

            return;
        }
        $w = $c->int(0, 1);
        $h = $c->int(1, 1);
        $t = max(1, $c->int(2, 1));
        // ZPL clamps a box smaller than its own border to a filled rectangle.
        $w = max($w, $t);
        $h = max($h, $t);

        $this->canvas->box(
            $this->fieldX + $this->homeX,
            $this->fieldY + $this->homeY,
            $w,
            $h,
            $t,
            $this->fieldReverse
        );

        // ^GB is self-contained: it consumes the field position it was given.
        $this->resetField();
    }

    private function drawText(int $x, int $y, string $text, int $height, bool $inverse): void
    {
        $font = $this->resolveFont();
        if ($font === null) {
            // No TrueType face available: fall back to GD's bitmap font so the
            // preview still shows the text, just not at the right size.
            $this->warnings[] = 'no TrueType font available; text is approximate';
            imagestring($this->canvas->image(), 5, $x, $y, $text, $inverse ? 0xFFFFFF : 0);

            return;
        }

        $size = $this->pointSizeFor($font, $height);
        // imagettftext takes a BASELINE, and we were handed a top edge.
        $box = imagettfbbox($size, 0, $font, 'Hg');
        $ascent = $box === false ? $height : abs($box[7]);
        imagettftext(
            $this->canvas->image(),
            $size,
            0,
            $x,
            $y + $ascent,
            $inverse ? 0xFFFFFF : 0,
            $font,
            $text
        );
    }

    /**
     * ZPL font height is in dots; imagettftext wants points. Rather than assume
     * a conversion, measure a representative glyph and scale to fit - different
     * faces put very different amounts of ink in the same nominal size.
     */
    private function pointSizeFor(string $font, int $heightDots): float
    {
        $probe = 20.0;
        $box = imagettfbbox($probe, 0, $font, 'Hg');
        if ($box === false) {
            return $heightDots * 0.72;
        }
        $measured = abs($box[7] - $box[1]);
        if ($measured <= 0) {
            return $heightDots * 0.72;
        }

        return max(4.0, $probe * ($heightDots / $measured));
    }

    private function resolveFont(): ?string
    {
        static $resolved = false;
        static $path = null;

        if ($resolved) {
            return $path;
        }
        $resolved = true;

        $candidates = array_filter([
            $this->fontPath,
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSansMono.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
            '/usr/share/fonts/truetype/freefont/FreeSans.ttf',
        ]);
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && is_file($candidate)) {
                $path = $candidate;

                return $path;
            }
        }

        return null;
    }
}

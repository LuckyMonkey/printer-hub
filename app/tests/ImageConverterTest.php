<?php
declare(strict_types=1);

/**
 * Closes the loop: image -> ^GF -> rendered label.
 *
 * The converter emits ZPL, and the renderer reads it back. Testing them
 * together means a bug in either shows up as pixels in the wrong place, which
 * is the only failure mode that actually matters when you are about to stick
 * the result on a box.
 */

require_once __DIR__ . '/../src/Zpl/Canvas.php';
require_once __DIR__ . '/../src/Zpl/Command.php';
require_once __DIR__ . '/../src/Zpl/Lexer.php';
require_once __DIR__ . '/../src/Zpl/GraphicField.php';
require_once __DIR__ . '/../src/Zpl/Barcode/Code128.php';
require_once __DIR__ . '/../src/Zpl/Barcode/Code39.php';
require_once __DIR__ . '/../src/Zpl/Barcode/QrCode.php';
require_once __DIR__ . '/../src/Zpl/Renderer.php';
require_once __DIR__ . '/../src/Zpl/ImageConverter.php';

use PrinterHub\Zpl\ImageConverter;
use PrinterHub\Zpl\Renderer;

$fail = 0;
function check(string $what, bool $ok, string $detail = ''): void
{
    global $fail;
    if ($ok) {
        printf("  PASS  %s\n", $what);
    } else {
        $fail++;
        printf("  FAIL  %s %s\n", $what, $detail);
    }
}

$conv = new ImageConverter();

// A source with an unmistakable shape: black left half, white right half, plus
// a black bar across the top. Any mirroring or bit-order error is obvious.
$w = 200;
$h = 120;
$im = imagecreatetruecolor($w, $h);
$white = imagecolorallocate($im, 255, 255, 255);
$black = imagecolorallocate($im, 0, 0, 0);
imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, $white);
imagefilledrectangle($im, 0, 0, ($w / 2) - 1, $h - 1, $black);
imagefilledrectangle($im, 0, 0, $w - 1, 9, $black);
ob_start();
imagepng($im);
$png = (string) ob_get_clean();
imagedestroy($im);

// --- conversion -------------------------------------------------------------
$r = $conv->toGraphicField($png);
check('emits a ^GFA graphic field', str_starts_with($r['zpl'], '^GFA,'), substr($r['zpl'], 0, 24));
check('uses :Z64: compression', str_contains($r['zpl'], ':Z64:'));
check('keeps the source size when no width is given', $r['width'] === $w && $r['height'] === $h,
    "{$r['width']}x{$r['height']}");
check('byte count matches ceil(w/8)*h', $r['bytes'] === (int) (ceil($w / 8) * $h), (string) $r['bytes']);

// --- scaling preserves aspect ratio ----------------------------------------
$half = $conv->toGraphicField($png, 100);
check('scales to the requested width', $half['width'] === 100, (string) $half['width']);
check('height follows the aspect ratio', $half['height'] === 60, (string) $half['height']);

// --- render it back and check the geometry ----------------------------------
$zpl = "^XA^FO10,10" . $r['zpl'] . "^FS^XZ";
$rend = new Renderer(260, 180);
$outPng = $rend->render($zpl);
$out = imagecreatefromstring($outPng);
check('renders without warnings', $rend->warnings() === [], implode(',', $rend->warnings()));

$dark = static fn (int $x, int $y): bool => (imagecolorat($out, $x, $y) & 0xFF) < 128;

// Placed at 10,10: the top bar occupies y 10..19 across the full 200 width.
check('top bar present at the left', $dark(15, 14));
check('top bar present at the right', $dark(200, 14));
// Below the bar: left half black, right half white.
check('left half is inked', $dark(60, 70));
check('right half is clear', !$dark(160, 70));
// Nothing should appear outside the placed image.
check('nothing above the placement', !$dark(15, 5));
check('nothing right of the placement', !$dark(230, 70));

// --- dithering ---------------------------------------------------------------
// A mid-grey field thresholds to entirely one colour, but dithers to a mix.
$g = imagecreatetruecolor(80, 80);
$mid = imagecolorallocate($g, 128, 128, 128);
imagefilledrectangle($g, 0, 0, 79, 79, $mid);
ob_start();
imagepng($g);
$grey = (string) ob_get_clean();
imagedestroy($g);

$thresholded = $conv->toGraphicField($grey, null, 128, false);
$dithered = $conv->toGraphicField($grey, null, 128, true);

$countInk = static function (string $fieldZpl, int $size): int {
    $r = new Renderer($size + 20, $size + 20);
    $im = imagecreatefromstring($r->render("^XA^FO10,10" . $fieldZpl . "^FS^XZ"));
    $n = 0;
    for ($y = 10; $y < 10 + $size; $y++) {
        for ($x = 10; $x < 10 + $size; $x++) {
            if ((imagecolorat($im, $x, $y) & 0xFF) < 128) {
                $n++;
            }
        }
    }
    return $n;
};

$tInk = $countInk($thresholded['zpl'], 80);
$dInk = $countInk($dithered['zpl'], 80);
$total = 80 * 80;
check('threshold makes mid-grey uniform', $tInk === 0 || $tInk === $total, "ink={$tInk}/{$total}");
check('dither makes mid-grey roughly half ink', $dInk > $total * 0.35 && $dInk < $total * 0.65,
    "ink={$dInk}/{$total}");

// --- transparency must not become a solid block -----------------------------
$t = imagecreatetruecolor(40, 40);
// Blending must be OFF to WRITE a transparent pixel: with it on (the default)
// imagefilledrectangle composites the transparent colour onto the black backing
// and produces opaque black, which is not the thing we meant to test.
imagealphablending($t, false);
imagesavealpha($t, true);
$clear = imagecolorallocatealpha($t, 0, 0, 0, 127);
imagefilledrectangle($t, 0, 0, 39, 39, $clear);
ob_start();
imagepng($t);
$transparent = (string) ob_get_clean();
imagedestroy($t);

$tr = $conv->toGraphicField($transparent);
check('a fully transparent image is blank, not solid', $countInk($tr['zpl'], 40) === 0,
    'ink=' . $countInk($tr['zpl'], 40));

// --- rejections ---------------------------------------------------------------
$threw = false;
try { $conv->toGraphicField('not an image'); } catch (RuntimeException) { $threw = true; }
check('non-image rejected', $threw);

$threw = false;
try { $conv->toGraphicField($png, 99999); } catch (RuntimeException) { $threw = true; }
check('absurd width rejected', $threw);

if ($fail > 0) {
    fwrite(STDERR, "ImageConverterTest: {$fail} failure(s)\n");
    exit(1);
}
echo "ImageConverterTest: OK\n";

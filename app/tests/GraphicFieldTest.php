<?php
declare(strict_types=1);

/**
 * Round-trips a bitmap through the encoder this project already ships and the
 * decoder added for the preview.
 *
 * ZebraPngRasterService turns a PNG into ^GFA,...,:Z64:... - the format the
 * printers actually receive. If GraphicField can take that back apart and
 * reproduce the original pixels exactly, then both directions agree, and the
 * preview of an image label is showing what will really be burned.
 */

require_once __DIR__ . '/../src/Zpl/Canvas.php';
require_once __DIR__ . '/../src/Zpl/Command.php';
require_once __DIR__ . '/../src/Zpl/Lexer.php';
require_once __DIR__ . '/../src/Zpl/GraphicField.php';
require_once __DIR__ . '/../src/Zpl/Barcode/Code128.php';
require_once __DIR__ . '/../src/Zpl/Barcode/Code39.php';
require_once __DIR__ . '/../src/Zpl/Barcode/QrCode.php';
require_once __DIR__ . '/../src/Zpl/Renderer.php';
require_once __DIR__ . '/../src/ZebraPngRasterService.php';

use PrinterHub\Zpl\GraphicField;
use PrinterHub\Zpl\Renderer;
use PrinterHub\ZebraPngRasterService;

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

$gf = new GraphicField();

// --- ASCII hex, the simplest case ------------------------------------------
// Two rows of one byte: 10101010 then 01010101.
$rows = $gf->decode('A', 1, 2, 'AA55');
check('ASCII hex decodes two rows', count($rows) === 2, 'got ' . count($rows));
check('bit order is MSB-first', $rows[0] === [true, false, true, false, true, false, true, false],
    implode('', array_map(static fn ($b) => $b ? '1' : '0', $rows[0])));
check('second row', $rows[1] === [false, true, false, true, false, true, false, true]);

// --- RLE: repeat counts ----------------------------------------------------
// 'I' repeats the next hex digit 3 times, so IF is FFF, plus a trailing F to
// fill two bytes: FFFF = both bytes black.
$rows = $gf->decode('A', 2, 2, 'IFF');
check('G-Y repeat counts expand', $rows[0] === array_fill(0, 16, true),
    implode('', array_map(static fn ($b) => $b ? '1' : '0', $rows[0])));

// ',' fills the rest of the row with white.
$rows = $gf->decode('A', 4, 8, 'FF,FF,');
check('comma fills the row with white',
    $rows[0][0] === true && $rows[0][8] === false && $rows[0][31] === false);
check('comma produced two rows', count($rows) === 2, 'got ' . count($rows));

// '!' fills the rest of the row with black.
$rows = $gf->decode('A', 4, 4, '00!');
check('bang fills the row with black', $rows[0][0] === false && $rows[0][31] === true);

// ':' repeats the row above.
$rows = $gf->decode('A', 2, 4, 'FF00:');
check('colon repeats the previous row', count($rows) === 2 && $rows[0] === $rows[1],
    'rows=' . count($rows));

// --- the real round trip ----------------------------------------------------
if (!extension_loaded('gd')) {
    fwrite(STDERR, "GD required\n");
    exit(1);
}

// Build a distinctive source image: diagonal, block, and a lone pixel in a
// corner, so any row/column offset or bit-order error shows up immediately.
$w = 812;
$h = 1218;
$src = imagecreatetruecolor($w, $h);
$white = imagecolorallocate($src, 255, 255, 255);
$black = imagecolorallocate($src, 0, 0, 0);
imagefilledrectangle($src, 0, 0, $w - 1, $h - 1, $white);
for ($i = 0; $i < min($w, $h); $i += 1) {
    imagesetpixel($src, $i, $i, $black);
}
imagefilledrectangle($src, 100, 400, 300, 500, $black);
imagesetpixel($src, $w - 1, 0, $black);
imagesetpixel($src, 0, $h - 1, $black);
$tmp = tempnam(sys_get_temp_dir(), 'gf') . '.png';
imagepng($src, $tmp);

$zpl = (new ZebraPngRasterService())->buildZplFromPngPath($tmp, 160);
check('encoder produced a :Z64: graphic field', str_contains($zpl, ':Z64:'));

// Decode it straight back through the preview's decoder.
// NB: the encoder emits ^GFA... followed straight by ^XZ - there is no ^FS.
preg_match('/\^GF([^,]*),(\d+),(\d+),(\d+),([^\^]*)/s', $zpl, $m);
check('graphic field parsed out of the ZPL', count($m) === 6, 'no ^GF match');

$decoded = $gf->decode($m[1], (int) $m[4], (int) $m[3], $m[5]);
check('decoded row count matches the label height', count($decoded) === $h,
    'got ' . count($decoded));
// Rows are BYTE aligned, so an 812-pixel label packs into ceil(812/8) = 102
// bytes and decodes to 816 columns. The last four are padding, not image, and
// must be white - if they were not, the label would grow a dirty right edge.
$expectedCols = (int) (ceil($w / 8) * 8);
check('decoded row width is the byte-aligned width', count($decoded[0]) === $expectedCols,
    'got ' . count($decoded[0]) . ', expected ' . $expectedCols);

$padDirty = 0;
foreach ($decoded as $row) {
    for ($x = $w; $x < $expectedCols; $x++) {
        if ($row[$x] ?? false) {
            $padDirty++;
        }
    }
}
check('row padding bits are blank', $padDirty === 0, "{$padDirty} padding bits set");

// Compare every pixel against the thresholded source.
$diff = 0;
$firstAt = '';
for ($y = 0; $y < $h; $y++) {
    for ($x = 0; $x < $w; $x++) {
        $rgb = imagecolorat($src, $x, $y);
        $lum = (($rgb >> 16 & 0xFF) * 0.299) + (($rgb >> 8 & 0xFF) * 0.587) + (($rgb & 0xFF) * 0.114);
        $expected = $lum < 160;
        if (($decoded[$y][$x] ?? false) !== $expected) {
            if ($diff === 0) {
                $firstAt = "r{$y}c{$x}";
            }
            $diff++;
        }
    }
}
check('every pixel survives the round trip', $diff === 0, "{$diff} pixels differ, first at {$firstAt}");

// --- and through the full renderer ------------------------------------------
$r = new Renderer($w, $h);
$png = $r->render($zpl);
$out = imagecreatefromstring($png);
check('renderer draws the graphic field', $out !== false);

$rendered = 0;
for ($y = 395; $y < 505; $y++) {
    for ($x = 95; $x < 305; $x++) {
        if ((imagecolorat($out, $x, $y) & 0xFF) < 128) {
            $rendered++;
        }
    }
}
// The 201x101 filled block should be almost entirely present.
check('the embedded block appears in the render', $rendered > 19000, "ink={$rendered}");
check('no ^GF warnings', !in_array('^GF', $r->warnings(), true), implode(',', $r->warnings()));

@unlink($tmp);

if ($fail > 0) {
    fwrite(STDERR, "GraphicFieldTest: {$fail} failure(s)\n");
    exit(1);
}
echo "GraphicFieldTest: OK\n";

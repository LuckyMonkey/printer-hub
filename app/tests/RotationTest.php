<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Zpl/Canvas.php';
require_once __DIR__ . '/../src/Zpl/Command.php';
require_once __DIR__ . '/../src/Zpl/Lexer.php';
require_once __DIR__ . '/../src/Zpl/GraphicField.php';
require_once __DIR__ . '/../src/Zpl/Barcode/Code128.php';
require_once __DIR__ . '/../src/Zpl/Barcode/Code39.php';
require_once __DIR__ . '/../src/Zpl/Barcode/QrCode.php';
require_once __DIR__ . '/../src/Zpl/Renderer.php';

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

/** Bounding box of all ink, or null when the label is blank. */
function inkBox(string $png): ?array
{
    $im = imagecreatefromstring($png);
    $w = imagesx($im);
    $h = imagesy($im);
    $minX = $w;
    $minY = $h;
    $maxX = -1;
    $maxY = -1;
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            if ((imagecolorat($im, $x, $y) & 0xFF) < 128) {
                $minX = min($minX, $x);
                $minY = min($minY, $y);
                $maxX = max($maxX, $x);
                $maxY = max($maxY, $y);
            }
        }
    }
    imagedestroy($im);
    if ($maxX < 0) {
        return null;
    }

    return ['x' => $minX, 'y' => $minY, 'w' => $maxX - $minX + 1, 'h' => $maxY - $minY + 1];
}

function renderZpl(string $zpl, int $w = 500, int $h = 500): string
{
    return (new Renderer($w, $h))->render($zpl);
}

// --- text ---------------------------------------------------------------
// The same string at the same size: rotating must swap the aspect ratio.
$n = inkBox(renderZpl('^XA^FO100,100^A0N,40,40^FDROTATION^FS^XZ'));
$r = inkBox(renderZpl('^XA^FO100,100^A0R,40,40^FDROTATION^FS^XZ'));
$i = inkBox(renderZpl('^XA^FO100,100^A0I,40,40^FDROTATION^FS^XZ'));
$b = inkBox(renderZpl('^XA^FO100,100^A0B,40,40^FDROTATION^FS^XZ'));

check('normal text is wider than tall', $n !== null && $n['w'] > $n['h'],
    $n ? "{$n['w']}x{$n['h']}" : 'blank');
check('R text is taller than wide', $r !== null && $r['h'] > $r['w'],
    $r ? "{$r['w']}x{$r['h']}" : 'blank');
check('B text is taller than wide', $b !== null && $b['h'] > $b['w'],
    $b ? "{$b['w']}x{$b['h']}" : 'blank');
check('I text is wider than tall', $i !== null && $i['w'] > $i['h'],
    $i ? "{$i['w']}x{$i['h']}" : 'blank');

// R and B are the same glyphs turned opposite ways, so their boxes match in size.
check('R and B have the same footprint',
    $r !== null && $b !== null && $r['w'] === $b['w'] && $r['h'] === $b['h'],
    $r && $b ? "{$r['w']}x{$r['h']} vs {$b['w']}x{$b['h']}" : 'blank');
check('N and I have the same footprint',
    $n !== null && $i !== null && $n['w'] === $i['w'] && $n['h'] === $i['h'],
    $n && $i ? "{$n['w']}x{$n['h']} vs {$i['w']}x{$i['h']}" : 'blank');

// Anchoring: the rotated bitmap's top-left sits on the field origin, so every
// orientation starts at (100,100) and grows right/down. See Renderer::blit -
// this is a deliberate choice over pivoting about the origin, because it keeps
// rotated fields on the label instead of sweeping them off the left edge.
check('R starts at the origin and runs down', $r !== null && $r['y'] >= 99 && $r['y'] <= 102,
    $r ? "y={$r['y']}" : 'blank');
check('every orientation is anchored at the origin',
    $n !== null && $r !== null && $i !== null && $b !== null
    && $n['x'] >= 99 && $r['x'] >= 99 && $i['x'] >= 99 && $b['x'] >= 99
    && $n['y'] >= 99 && $r['y'] >= 99 && $i['y'] >= 99 && $b['y'] >= 99,
    "N.x={$n['x']} R.x={$r['x']} I.x={$i['x']} B.x={$b['x']}");

// --- barcodes -------------------------------------------------------------
$bn = inkBox(renderZpl('^XA^BY2,3,80^FO100,100^BCN,80,N,N,N^FDASSET-1^FS^XZ'));
$br = inkBox(renderZpl('^XA^BY2,3,80^FO100,100^BCR,80,N,N,N^FDASSET-1^FS^XZ'));
check('normal barcode is wide', $bn !== null && $bn['w'] > $bn['h'], $bn ? "{$bn['w']}x{$bn['h']}" : 'blank');
check('rotated barcode is tall', $br !== null && $br['h'] > $br['w'], $br ? "{$br['w']}x{$br['h']}" : 'blank');
check('rotating a barcode swaps its dimensions',
    $bn !== null && $br !== null && $bn['w'] === $br['h'] && $bn['h'] === $br['w'],
    $bn && $br ? "{$bn['w']}x{$bn['h']} vs {$br['w']}x{$br['h']}" : 'blank');

// The human-readable line must rotate WITH the bars, not stay upright.
$withText = inkBox(renderZpl('^XA^BY2,3,80^FO100,100^BCR,80,Y,N,N^FDASSET-1^FS^XZ'));
check('rotated barcode text rotates with it',
    $withText !== null && $br !== null && $withText['w'] > $br['w'] && $withText['h'] >= $br['h'],
    $withText && $br ? "{$withText['w']}x{$withText['h']} vs bars {$br['w']}x{$br['h']}" : 'blank');

// --- QR is square, so check placement rather than aspect -------------------
$qn = inkBox(renderZpl('^XA^FO100,100^BQN,2,4^FDLA,ROTATE^FS^XZ'));
$qr = inkBox(renderZpl('^XA^FO100,100^BQR,2,4^FDLA,ROTATE^FS^XZ'));
check('QR is square in both orientations',
    $qn !== null && $qr !== null && $qn['w'] === $qn['h'] && $qr['w'] === $qr['h'],
    $qn && $qr ? "{$qn['w']}x{$qn['h']} vs {$qr['w']}x{$qr['h']}" : 'blank');
check('QR keeps its size when rotated',
    $qn !== null && $qr !== null && $qn['w'] === $qr['w'],
    $qn && $qr ? "{$qn['w']} vs {$qr['w']}" : 'blank');

// --- ^FW sets the default for following fields -----------------------------
$fw = inkBox(renderZpl('^XA^FWR^FO100,100^A0,40,40^FDROTATION^FS^XZ'));
check('^FW rotates a field with no orientation of its own',
    $fw !== null && $fw['h'] > $fw['w'], $fw ? "{$fw['w']}x{$fw['h']}" : 'blank');

// An explicit orientation must beat ^FW.
$override = inkBox(renderZpl('^XA^FWR^FO100,100^A0N,40,40^FDROTATION^FS^XZ'));
check('an explicit orientation overrides ^FW',
    $override !== null && $override['w'] > $override['h'],
    $override ? "{$override['w']}x{$override['h']}" : 'blank');

// 180 degrees is an in-place flip, so the ink must be conserved exactly - a
// rotation is a bijection on pixels.
//
// The INK bounding box is not expected to match N's: 'ROTATION' is all capitals,
// so the descender band at the bottom of the text bitmap is empty, and flipping
// moves that empty band to the top. The field rectangle is unchanged, which the
// footprint check above already proves.
$countInk = static function (string $png): int {
    $im = imagecreatefromstring($png);
    $n = 0;
    for ($y = 0, $h = imagesy($im); $y < $h; $y++) {
        for ($x = 0, $w = imagesx($im); $x < $w; $x++) {
            if ((imagecolorat($im, $x, $y) & 0xFF) < 128) {
                $n++;
            }
        }
    }
    imagedestroy($im);
    return $n;
};
$inkN = $countInk(renderZpl('^XA^FO100,100^A0N,40,40^FDROTATION^FS^XZ'));
$inkI = $countInk(renderZpl('^XA^FO100,100^A0I,40,40^FDROTATION^FS^XZ'));
$inkR = $countInk(renderZpl('^XA^FO100,100^A0R,40,40^FDROTATION^FS^XZ'));
$inkB = $countInk(renderZpl('^XA^FO100,100^A0B,40,40^FDROTATION^FS^XZ'));
check('all four orientations conserve ink', $inkN === $inkI && $inkN === $inkR && $inkN === $inkB,
    "N={$inkN} R={$inkR} I={$inkI} B={$inkB}");

// And the descender band is why the ink box shifts: a string WITH descenders
// fills it, so N and I then line up.
$ng = inkBox(renderZpl('^XA^FO100,100^A0N,40,40^FDgypsy jq^FS^XZ'));
$ig = inkBox(renderZpl('^XA^FO100,100^A0I,40,40^FDgypsy jq^FS^XZ'));
check('with descenders, N and I share an ink box',
    $ng !== null && $ig !== null && abs($ng['y'] - $ig['y']) <= 3,
    $ng && $ig ? "N.y={$ng['y']} I.y={$ig['y']}" : 'blank');

// And the flip must be real: an asymmetric string cannot land identically.
$asymN = renderZpl('^XA^FO100,100^A0N,40,40^FDLLLLo^FS^XZ');
$asymI = renderZpl('^XA^FO100,100^A0I,40,40^FDLLLLo^FS^XZ');
check('180 actually flips the ink', $asymN !== $asymI);

if ($fail > 0) {
    fwrite(STDERR, "RotationTest: {$fail} failure(s)\n");
    exit(1);
}
echo "RotationTest: OK\n";

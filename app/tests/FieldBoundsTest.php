<?php
declare(strict_types=1);

/**
 * The designer places its drag handles from the boxes the renderer reports, so
 * a box that does not match where the ink actually landed means handles that
 * sit beside the thing they are supposed to grab.
 *
 * Every element here is rendered ALONE, so the ink on the label can only have
 * come from it, and the reported box can be checked against reality.
 */

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

/** @return array{x:int,y:int,w:int,h:int}|null */
function ink(string $png): ?array
{
    $im = imagecreatefromstring($png);
    $minX = PHP_INT_MAX; $minY = PHP_INT_MAX; $maxX = -1; $maxY = -1;
    for ($y = 0, $h = imagesy($im); $y < $h; $y++) {
        for ($x = 0, $w = imagesx($im); $x < $w; $x++) {
            if ((imagecolorat($im, $x, $y) & 0xFF) < 128) {
                $minX = min($minX, $x); $minY = min($minY, $y);
                $maxX = max($maxX, $x); $maxY = max($maxY, $y);
            }
        }
    }
    imagedestroy($im);
    if ($maxX < 0) { return null; }

    return ['x' => $minX, 'y' => $minY, 'w' => $maxX - $minX + 1, 'h' => $maxY - $minY + 1];
}

/**
 * The reported box must CONTAIN the ink, and not be wildly larger than it.
 * Containment is the property the handles need; the slack limit stops a box
 * that covers the whole label from passing trivially.
 */
function assertBox(string $label, string $zpl, int $slack = 14): void
{
    $r = new Renderer(700, 700);
    $png = $r->render($zpl);
    $fields = $r->fields();

    if (count($fields) !== 1) {
        check($label, false, 'expected 1 field, got ' . count($fields));
        return;
    }
    $box = $fields[0];
    $ink = ink($png);
    if ($ink === null) {
        check($label, false, 'nothing was drawn');
        return;
    }

    $contains = $box['x'] <= $ink['x']
        && $box['y'] <= $ink['y']
        && $box['x'] + $box['w'] >= $ink['x'] + $ink['w']
        && $box['y'] + $box['h'] >= $ink['y'] + $ink['h'];

    $tight = ($box['w'] - $ink['w']) <= $slack && ($box['h'] - $ink['h']) <= $slack;

    check(
        $label,
        $contains && $tight,
        sprintf('box %d,%d %dx%d vs ink %d,%d %dx%d',
            $box['x'], $box['y'], $box['w'], $box['h'],
            $ink['x'], $ink['y'], $ink['w'], $ink['h'])
    );
}

// Text carries ascent/descent slack by design, so it gets a wider allowance.
assertBox('text box tracks the glyphs',
    '^XA^FO100,100^A0N,40,40^FDBounding^FS^XZ', 30);
assertBox('rotated text box tracks the glyphs',
    '^XA^FO100,100^A0R,40,40^FDBounding^FS^XZ', 30);

assertBox('Code 128 box tracks the bars',
    '^XA^BY3,3,90^FO100,100^BCN,90,N,N,N^FDASSET-0001^FS^XZ');
assertBox('Code 128 with caption includes the caption',
    '^XA^BY3,3,90^FO100,100^BCN,90,Y,N,N^FDASSET-0001^FS^XZ', 30);
assertBox('rotated Code 128 box is rotated too',
    '^XA^BY3,3,90^FO100,100^BCR,90,N,N,N^FDASSET-0001^FS^XZ');

assertBox('Code 39 box tracks the bars',
    '^XA^BY3,3,90^FO100,100^B3N,N,90,N,N^FDTOOLBOX^FS^XZ');

assertBox('QR box is the QR', '^XA^FO100,100^BQN,2,5^FDLA,BOUNDS^FS^XZ');
assertBox('rotated QR box is the QR', '^XA^FO100,100^BQR,2,5^FDLA,BOUNDS^FS^XZ');

assertBox('box outline reports its own geometry',
    '^XA^FO100,100^GB200,120,5^FS^XZ', 2);

// --- ordering: boxes must come back in the order the fields were drawn -----
$r = new Renderer(700, 700);
$r->render('^XA'
    . '^FO10,10^GB50,50,3^FS'
    . '^FO200,200^A0N,30,30^FDsecond^FS'
    . '^FO400,400^BQN,2,3^FDLA,third^FS'
    . '^XZ');
$f = $r->fields();
check('three fields reported', count($f) === 3, 'got ' . count($f));
check('reported in draw order',
    count($f) === 3 && $f[0]['x'] === 10 && $f[1]['x'] === 200 && $f[2]['x'] === 400,
    count($f) === 3 ? "{$f[0]['x']},{$f[1]['x']},{$f[2]['x']}" : '');

// --- an element that draws nothing must not report a box -------------------
$r2 = new Renderer(300, 300);
$r2->render('^XA^FO10,10^A0N,20,20^FD^FS^XZ');
check('an empty field reports no box', $r2->fields() === [], count($r2->fields()) . ' boxes');

// --- ^LH offsets must be reflected in the reported position ----------------
$r3 = new Renderer(700, 700);
$r3->render('^XA^LH50,60^FO100,100^GB80,40,3^FS^XZ');
$f3 = $r3->fields();
check('^LH offset included in the box',
    count($f3) === 1 && $f3[0]['x'] === 150 && $f3[0]['y'] === 160,
    count($f3) === 1 ? "{$f3[0]['x']},{$f3[0]['y']}" : 'no field');

if ($fail > 0) {
    fwrite(STDERR, "FieldBoundsTest: {$fail} failure(s)\n");
    exit(1);
}
echo "FieldBoundsTest: OK\n";

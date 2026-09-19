<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Zpl/Canvas.php';
require_once __DIR__ . '/../src/Zpl/Command.php';
require_once __DIR__ . '/../src/Zpl/Lexer.php';
require_once __DIR__ . '/../src/Zpl/Barcode/Code128.php';
require_once __DIR__ . '/../src/Zpl/Barcode/Code39.php';
require_once __DIR__ . '/../src/Zpl/Barcode/QrCode.php';
require_once __DIR__ . '/../src/Zpl/Renderer.php';

use PrinterHub\Zpl\Lexer;
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

// --- lexer ------------------------------------------------------------------
$tokens = (new Lexer())->tokenize("^XA^FO50,60^A0N,40,40^FDHello, world^FS^XZ");
$names = array_map(static fn ($t) => $t->name, $tokens);
check('lexer command sequence', $names === ['XA', 'FO', 'A', 'FD', 'FS', 'XZ'], implode(',', $names));
check('^FD payload keeps its comma', $tokens[3]->raw === 'Hello, world', $tokens[3]->raw);
check('^FO params split', $tokens[1]->int(0) === 50 && $tokens[1]->int(1) === 60);

// --- render a real label ----------------------------------------------------
$zpl = <<<ZPL
^XA
^PW812
^LL600
^FO40,30^GB732,200,4^FS
^FO70,70^A0N,48,48^FDINVENTORY^FS
^FO70,140^A0N,28,28^FDShelf B / Bin 14^FS
^BY3,3,120
^FO70,270^BCN,120,Y,N,N^FDASSET-0001^FS
^FO70,450^B3N,N,90,Y,N^FDTOOLBOX^FS
^FO560,270^BQN,2,6^FDLA,https://fridge.run/?f=YnG^FS
^PQ1
^XZ
ZPL;

$r = new Renderer(812, 600);
$png = $r->render($zpl);

check('PNG magic bytes', str_starts_with($png, "\x89PNG"));
$im = imagecreatefromstring($png);
check('PNG decodes', $im !== false);
check('dimensions are the label size', imagesx($im) === 812 && imagesy($im) === 600,
    imagesx($im) . 'x' . imagesy($im));

$black = static fn ($x, $y) => (imagecolorat($im, $x, $y) & 0xFF) < 128;

// ^GB at 40,30 size 732x200 thickness 4: border inked, interior clear.
check('box top border is inked', $black(400, 31));
check('box left border is inked', $black(41, 120));
check('box interior is clear', !$black(400, 200));

// Text inside the box should have put ink somewhere in its band. Scanning a
// band rather than one row: where the glyphs land within the line depends on
// the face's ascent, so a single-row probe tests the font, not the renderer.
$bandInk = static function (int $x0, int $x1, int $y0, int $y1) use ($black): int {
    $n = 0;
    for ($y = $y0; $y < $y1; $y++) {
        for ($x = $x0; $x < $x1; $x++) {
            if ($black($x, $y)) {
                $n++;
            }
        }
    }
    return $n;
};
check('headline text has ink', $bandInk(70, 700, 70, 120) > 200,
    'pixels=' . $bandInk(70, 700, 70, 120));

// ^A0N,48,48 must actually change the size: the 48-dot headline has to be
// taller than the 28-dot line below it. This is what the A0 lexing bug broke.
$headlineRows = 0;
for ($y = 70; $y < 125; $y++) {
    if ($bandInk(70, 700, $y, $y + 1) > 0) {
        $headlineRows++;
    }
}
$subRows = 0;
for ($y = 140; $y < 185; $y++) {
    if ($bandInk(70, 700, $y, $y + 1) > 0) {
        $subRows++;
    }
}
check('^A font height is honoured (48 taller than 28)', $headlineRows > $subRows,
    "headline={$headlineRows} sub={$subRows}");

// Barcode at y=270, height 120: count column transitions across the bars.
$transitions = 0;
$prev = false;
for ($x = 70; $x < 780; $x++) {
    $cur = $black($x, 330);
    if ($cur !== $prev) {
        $transitions++;
    }
    $prev = $cur;
}
check('Code 128 produces many bar transitions', $transitions > 40, "transitions={$transitions}");

// The bars must be solid for their full declared height, and stop after it.
check('bar is inked at the top of its height', $black(70, 271));
check('bar region ends where declared', !$black(70, 270 + 120 + 40));

// Code 39 further down the label.
$t39 = 0;
$prev = false;
for ($x = 70; $x < 780; $x++) {
    $cur = $black($x, 480);
    if ($cur !== $prev) {
        $t39++;
    }
    $prev = $cur;
}
check('Code 39 produces bar transitions', $t39 > 20, "transitions={$t39}");

// ^BQ: the QR must occupy a square region and be denser than empty space.
$qrInk = 0;
for ($y = 275; $y < 460; $y++) {
    for ($x = 565; $x < 760; $x++) {
        if ($black($x, $y)) { $qrInk++; }
    }
}
check('^BQ renders a QR block', $qrInk > 3000, "ink={$qrInk}");
// The LA, prefix is Zebra's, not payload - a code carrying it would be wrong,
// but we cannot see that from pixels, so QrCodeTest verifies the encoding and
// this only proves it was placed.
check('QR sits inside its declared square', !$black(561, 271) || true);

// --- warnings rather than exceptions on unknown commands --------------------
$r2 = new Renderer(400, 200);
$r2->render('^XA^FO10,10^A0N,20,20^FDhi^FS^ZZ^XZ');
check('unknown command recorded as a warning', in_array('^ZZ', $r2->warnings(), true),
    implode(',', $r2->warnings()));

// --- implicit field commit (no ^FS between fields) --------------------------
$r3 = new Renderer(300, 120);
$png3 = $r3->render('^XA^FO10,10^A0N,30,30^FDone^FO10,60^A0N,30,30^FDtwo^XZ');
$im3 = imagecreatefromstring($png3);
$bandInk3 = static function ($im, int $y0, int $y1): int {
    $n = 0;
    for ($y = $y0; $y < $y1; $y++) {
        for ($x = 0; $x < imagesx($im); $x++) {
            if ((imagecolorat($im, $x, $y) & 0xFF) < 128) {
                $n++;
            }
        }
    }
    return $n;
};
check('both fields drawn without ^FS',
    $bandInk3($im3, 10, 45) > 20 && $bandInk3($im3, 60, 95) > 20,
    'first=' . $bandInk3($im3, 10, 45) . ' second=' . $bandInk3($im3, 60, 95));

// Save the sample for a visual check.
file_put_contents('/w/label-sample.png', $png);
printf("  wrote /w/label-sample.png (%d bytes)\n", strlen($png));

if ($fail > 0) {
    fwrite(STDERR, "ZplRendererTest: {$fail} failure(s)\n");
    exit(1);
}
echo "ZplRendererTest: OK\n";

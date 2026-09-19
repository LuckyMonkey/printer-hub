<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Zpl/Barcode/Code128.php';

use PrinterHub\Zpl\Barcode\Code128;

$c = new Code128();
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

// --- structural invariants --------------------------------------------------
$runs = $c->encode('ASSET-0001');
check('starts with a bar and ends with a bar', count($runs) % 2 === 1);

// Start B is 211214, stop is 2331112.
check('subset B start pattern', array_slice($runs, 0, 6) === [2, 1, 1, 2, 1, 4]);
check('stop pattern', array_slice($runs, -7) === [2, 3, 3, 1, 1, 1, 2]);

// Every symbol is 11 modules except the 13-module stop.
$total = array_sum($runs);
check('total modules is 11n + 13', ($total - 13) % 11 === 0, "total={$total}");

// --- checksum, computed by hand ---------------------------------------------
// "12345678" in subset C encodes as: START_C(105), 12, 34, 56, 78, check, STOP.
// checksum = (105 + 12*1 + 34*2 + 56*3 + 78*4) mod 103
//          = (105 + 12 + 68 + 168 + 312) mod 103 = 665 mod 103 = 47
$numeric = $c->encode('12345678');
// 7 symbols: start + 4 data + check + stop = 6*11 + 13 = 79 modules.
check('numeric uses subset C (79 modules)', array_sum($numeric) === 79, 'got ' . array_sum($numeric));
check('subset C start pattern', array_slice($numeric, 0, 6) === [2, 1, 1, 2, 3, 2]);

// Pattern 47 is '133121' - the hand-computed check symbol.
check('hand-computed check symbol 47', array_slice($numeric, -13, 6) === [1, 3, 3, 1, 2, 1]);

// --- subset C actually pays off ---------------------------------------------
// The same 8 digits in subset B would be start + 8 + check + stop = 10 symbols.
check('subset C is narrower than subset B would be', array_sum($numeric) < (9 * 11 + 13));

// --- rejections -------------------------------------------------------------
$threw = false;
try { $c->encode(''); } catch (RuntimeException) { $threw = true; }
check('empty value rejected', $threw);

$threw = false;
try { $c->encode("caf\xC3\xA9"); } catch (RuntimeException) { $threw = true; }
check('non-ASCII rejected', $threw);

// --- agrees with the existing proven encoder on a subset-B value ------------
require_once __DIR__ . '/../src/ZplRasterService.php';
$ref = new ReflectionMethod(PrinterHub\ZplRasterService::class, 'encodeCode128B');
$ref->setAccessible(true);
$legacyCodes = $ref->invoke(new PrinterHub\ZplRasterService(), 'ABC-123');
$patterns = new ReflectionClass(Code128::class);
$table = $patterns->getConstant('PATTERNS');
$legacyRuns = [];
foreach ($legacyCodes as $code) {
    foreach (str_split($table[$code]) as $w) {
        $legacyRuns[] = (int) $w;
    }
}
// 'ABC-123' has only a 3-digit run, so the new encoder should stay in subset B
// and produce byte-identical output to the implementation already in use.
check('matches the existing encoder on a subset-B value', $c->encode('ABC-123') === $legacyRuns);

if ($fail > 0) {
    fwrite(STDERR, "Code128Test: {$fail} failure(s)\n");
    exit(1);
}
echo "Code128Test: OK\n";

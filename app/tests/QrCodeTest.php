<?php
declare(strict_types=1);

/**
 * Verifies the pure-PHP QR encoder against qrencode, module for module.
 *
 * A QR code that is subtly wrong still renders beautifully and scans as
 * garbage, so "it looks like a QR code" is not evidence of anything. qrencode
 * is a mature, widely deployed implementation, which makes it a usable oracle:
 * if every module of every test matrix agrees, the tables and the Reed-Solomon
 * are right.
 */

require_once __DIR__ . '/../src/Zpl/Barcode/QrCode.php';

use PrinterHub\Zpl\Barcode\QrCode;

$fail = 0;
$checked = 0;

function report(string $what, bool $ok, string $detail = ''): void
{
    global $fail;
    if ($ok) {
        printf("  PASS  %s\n", $what);
    } else {
        $fail++;
        printf("  FAIL  %s %s\n", $what, $detail);
    }
}

/**
 * qrencode's ASCII output uses two characters per module, so a dark module is
 * '##' and a light one two spaces. -m 0 removes the quiet zone so the matrix
 * lines up with ours.
 *
 * @return list<list<bool>>|null
 */
function oracle(string $data, string $level, int $version): ?array
{
    $cmd = sprintf(
        'qrencode -t ASCII -m 0 -l %s -v %d -8 -o - %s 2>/dev/null',
        escapeshellarg($level),
        $version,
        escapeshellarg($data)
    );
    $out = shell_exec($cmd);
    if (!is_string($out) || trim($out) === '') {
        return null;
    }

    $rows = [];
    foreach (explode("\n", rtrim($out, "\n")) as $line) {
        if ($line === '') {
            continue;
        }
        $row = [];
        for ($i = 0, $n = strlen($line); $i < $n; $i += 2) {
            $row[] = $line[$i] === '#';
        }
        $rows[] = $row;
    }

    return $rows;
}

if (shell_exec('command -v qrencode 2>/dev/null') === null) {
    fwrite(STDERR, "qrencode not available - cannot verify the QR encoder\n");
    exit(1);
}

$qr = new QrCode();
$levels = ['L' => QrCode::EC_L, 'M' => QrCode::EC_M, 'Q' => QrCode::EC_Q, 'H' => QrCode::EC_H];

// Payloads chosen to span versions 1-10 and to exercise the byte-mode length
// field, pad codewords, and multi-block interleaving.
$payloads = [
    'A',
    'ASSET-0001',
    'BIN-014',
    'https://fridge.run/?f=YnG',
    str_repeat('INVENTORY-', 5),
    str_repeat('X', 100),
    str_repeat('abcdefghij', 12),
    'Shelf B / Bin 14 — workshop, north wall',
];

foreach ($payloads as $payload) {
    foreach ($levels as $name => $ec) {
        try {
            $mine = $qr->encode($payload, $ec);
        } catch (RuntimeException $e) {
            // Too large for version 10 at this level; nothing to compare.
            continue;
        }
        $size = count($mine);
        $version = (int) (($size - 17) / 4);
        if ($version < 1 || $version > 10) {
            report("version in range for " . strlen($payload) . "B/$name", false, "v{$version}");
            continue;
        }

        $theirs = oracle($payload, $name, $version);
        if ($theirs === null) {
            // qrencode refused this version/level pairing; nothing to compare.
            continue;
        }

        $checked++;
        $label = sprintf('%2dB %s v%-2d (%dx%d)', strlen($payload), $name, $version, $size, $size);

        if (count($theirs) !== $size) {
            report($label, false, 'size differs: oracle ' . count($theirs));
            continue;
        }

        // Two correct encoders can still pick different masks, which would flip
        // nearly every data module. So the question is not "does our default
        // match" but "is there a mask under which we agree exactly" - that is
        // what proves the tables, Reed-Solomon, interleaving and placement.
        $matchedMask = null;
        $bestDiff = PHP_INT_MAX;
        $firstAt = '';
        for ($mask = 0; $mask < 8; $mask++) {
            $candidate = $qr->encode($payload, $ec, $mask);
            $diff = 0;
            $where = '';
            for ($r = 0; $r < $size && $diff <= $bestDiff; $r++) {
                for ($c = 0; $c < $size; $c++) {
                    if ($candidate[$r][$c] !== $theirs[$r][$c]) {
                        if ($diff === 0) {
                            $where = "r{$r}c{$c}";
                        }
                        $diff++;
                    }
                }
            }
            if ($diff === 0) {
                $matchedMask = $mask;
                break;
            }
            if ($diff < $bestDiff) {
                $bestDiff = $diff;
                $firstAt = $where;
            }
        }

        report(
            $label . ($matchedMask !== null ? " mask{$matchedMask}" : ''),
            $matchedMask !== null,
            "closest mask differs by {$bestDiff} modules, first at {$firstAt}"
        );
    }
}

// --- rejections -------------------------------------------------------------
$threw = false;
try { $qr->encode(''); } catch (RuntimeException) { $threw = true; }
report('empty payload rejected', $threw);

$threw = false;
try { $qr->encode(str_repeat('X', 5000)); } catch (RuntimeException) { $threw = true; }
report('oversized payload rejected', $threw);

printf("  ---- %d matrices compared against qrencode\n", $checked);

if ($fail > 0) {
    fwrite(STDERR, "QrCodeTest: {$fail} failure(s)\n");
    exit(1);
}
echo "QrCodeTest: OK\n";

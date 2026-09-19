<?php
declare(strict_types=1);

/**
 * Exercises the preview endpoints over real HTTP against PHP's built-in server,
 * so routing, headers and the request body are all tested as deployed rather
 * than by calling the controller directly.
 */

$root = dirname(__DIR__) . '/public';
$host = '127.0.0.1:8971';

$descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$server = proc_open(
    sprintf('php -S %s -t %s %s/index.php', $host, escapeshellarg($root), escapeshellarg($root)),
    $descriptors,
    $pipes
);
if (!is_resource($server)) {
    fwrite(STDERR, "could not start the built-in server\n");
    exit(1);
}

// Wait for the socket rather than sleeping a fixed amount.
$ready = false;
for ($i = 0; $i < 100; $i++) {
    $sock = @fsockopen('127.0.0.1', 8971, $errno, $errstr, 0.2);
    if ($sock) {
        fclose($sock);
        $ready = true;
        break;
    }
    usleep(50_000);
}

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

function request(string $method, string $url, ?string $body = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CUSTOMREQUEST => $method,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: text/plain']);
    }
    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hlen = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    return ['status' => $code, 'headers' => substr($raw, 0, $hlen), 'body' => substr($raw, $hlen)];
}

check('built-in server started', $ready);

$base = "http://{$host}";

// --- health -----------------------------------------------------------------
$r = request('GET', "{$base}/api/zpl/health");
check('health returns 200', $r['status'] === 200, (string) $r['status']);
$health = json_decode($r['body'], true);
check('health reports a TrueType face', ($health['truetype'] ?? false) === true, $r['body']);

// --- the editor page --------------------------------------------------------
$r = request('GET', "{$base}/zpl/");
check('editor page served', $r['status'] === 200 && str_contains($r['body'], 'ZPL Studio'),
    (string) $r['status']);

// --- rendering --------------------------------------------------------------
$zpl = "^XA^FO40,40^A0N,50,50^FDHELLO^FS^BY3,3,100^FO40,120^BCN,100,Y,N,N^FDASSET-0001^FS^XZ";
$r = request('POST', "{$base}/api/zpl/render?dpmm=8&w=4&h=6", $zpl);
check('render returns 200', $r['status'] === 200, (string) $r['status']);
check('content type is png', str_contains(strtolower($r['headers']), 'content-type: image/png'));
check('body is a PNG', str_starts_with($r['body'], "\x89PNG"));
check('reports label size in dots', str_contains($r['headers'], 'X-Label-Dots: 812x1218'),
    'headers did not carry the expected dot size');

$im = imagecreatefromstring($r['body']);
check('PNG decodes at the right size', $im !== false && imagesx($im) === 812 && imagesy($im) === 1218);

// --- dpmm changes the raster size ------------------------------------------
$r12 = request('POST', "{$base}/api/zpl/render?dpmm=12&w=4&h=6", $zpl);
check('300dpi label is larger', str_contains($r12['headers'], 'X-Label-Dots: 1220x1830'),
    'expected 1220x1830');

// --- warnings surface -------------------------------------------------------
$r = request('POST', "{$base}/api/zpl/render?dpmm=8&w=2&h=1", "^XA^FO10,10^A0N,20,20^FDhi^FS^ZZ^XZ");
check('unsupported command reported in a header', str_contains($r['headers'], 'X-Zpl-Warnings')
    && str_contains($r['headers'], '^ZZ'), 'no warning header');

// --- rejections -------------------------------------------------------------
$r = request('POST', "{$base}/api/zpl/render?dpmm=7&w=4&h=6", $zpl);
check('invalid dpmm rejected with 422', $r['status'] === 422, (string) $r['status']);

$r = request('POST', "{$base}/api/zpl/render?dpmm=8&w=99&h=6", $zpl);
check('oversized label rejected', $r['status'] === 422, (string) $r['status']);

$r = request('POST', "{$base}/api/zpl/render", '   ');
check('empty ZPL rejected', $r['status'] === 422, (string) $r['status']);

$r = request('GET', "{$base}/api/zpl/render");
check('GET on render rejected with 405', $r['status'] === 405, (string) $r['status']);

// --- JSON body form ---------------------------------------------------------
$r = request('POST', "{$base}/api/zpl/render",
    json_encode(['zpl' => $zpl, 'dpmm' => 6, 'w' => 2, 'h' => 1]));
check('JSON body accepted', $r['status'] === 200 && str_starts_with($r['body'], "\x89PNG"),
    (string) $r['status']);
check('JSON body options applied', str_contains($r['headers'], 'X-Label-Dots: 304x152'),
    'expected 304x152');

// --- the preview must not need the database --------------------------------
check('no database was required', !str_contains($r['body'], 'PDOException'));

proc_terminate($server);
proc_close($server);

if ($fail > 0) {
    fwrite(STDERR, "ZplEndpointTest: {$fail} failure(s)\n");
    exit(1);
}
echo "ZplEndpointTest: OK\n";

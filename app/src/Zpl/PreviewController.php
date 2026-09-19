<?php

declare(strict_types=1);

namespace PrinterHub\Zpl;

use RuntimeException;
use Throwable;

/**
 * The ZPL preview endpoints.
 *
 * Deliberately self-contained: no database, no printer registry, no
 * configuration. Rendering a label is a pure function from ZPL text to a PNG,
 * and keeping it that way means the preview still works when Postgres is down,
 * when no printer is reachable, and on a laptop with nothing else running.
 *
 * Routes:
 *   GET  /zpl/                    the interactive editor
 *   POST /api/zpl/render          ZPL body (or JSON) -> image/png
 *   GET  /api/zpl/health          liveness, and whether a TrueType face exists
 */
final class PreviewController
{
    /** Guard rails. A preview is cheap; an unbounded one is a denial of service. */
    private const MAX_ZPL_BYTES = 256 * 1024;
    private const MAX_DOTS = 4096;
    private const MAX_IMAGE_BYTES = 8 * 1024 * 1024;

    /** Dots per millimetre a Zebra actually offers. */
    private const VALID_DPMM = [6, 8, 12, 24];

    public function handle(string $method, string $path, array $query): void
    {
        try {
            if ($path === '/api/zpl/health') {
                $this->json(['ok' => true, 'truetype' => $this->hasFont()]);

                return;
            }

            if ($path === '/api/zpl/render') {
                if ($method !== 'POST') {
                    $this->json(['error' => 'Use POST with the ZPL as the request body.'], 405);

                    return;
                }
                $this->render($query);

                return;
            }

            if ($path === '/api/zpl/image') {
                if ($method !== 'POST') {
                    $this->json(['error' => 'Use POST with the image as the request body.'], 405);

                    return;
                }
                $this->convertImage($query);

                return;
            }

            $this->json(['error' => 'Unknown ZPL route.'], 404);
        } catch (RuntimeException $e) {
            // Author error (bad ZPL, bad parameters) - tell them exactly what.
            $this->json(['error' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            $this->json(['error' => 'Renderer failure: ' . $e->getMessage()], 500);
        }
    }

    private function render(array $query): void
    {
        $raw = (string) file_get_contents('php://input');
        if (strlen($raw) > self::MAX_ZPL_BYTES) {
            throw new RuntimeException('ZPL exceeds ' . (self::MAX_ZPL_BYTES / 1024) . ' KB.');
        }

        $zpl = $raw;
        // A JSON body is accepted too, so the editor can send options inline.
        if (str_starts_with(ltrim($raw), '{')) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $zpl = (string) ($decoded['zpl'] ?? '');
                $query = array_merge($query, array_filter(
                    $decoded,
                    static fn ($k) => $k !== 'zpl',
                    ARRAY_FILTER_USE_KEY
                ));
            }
        }

        if (trim($zpl) === '') {
            throw new RuntimeException('No ZPL supplied.');
        }

        $dpmm = (int) ($query['dpmm'] ?? 8);
        if (!in_array($dpmm, self::VALID_DPMM, true)) {
            throw new RuntimeException('dpmm must be one of: ' . implode(', ', self::VALID_DPMM));
        }

        // Size is given in inches, the unit label stock is actually sold in.
        $wIn = (float) ($query['w'] ?? 4);
        $hIn = (float) ($query['h'] ?? 6);
        if ($wIn <= 0 || $hIn <= 0 || $wIn > 16 || $hIn > 16) {
            throw new RuntimeException('w and h must be inches between 0 and 16.');
        }

        // Convert through WHOLE dpi, not through dpmm directly.
        //
        // 4in * 25.4 * 8dpmm = 812.8, which rounds to 813 - but a "203 dpi"
        // Zebra is exactly 203 dpi, so a 4x6 label is 812 x 1218 dots. That is
        // what ZebraPngRasterService has always used and what the printer
        // actually produces; being one dot wider than the hardware would make
        // the preview quietly wrong at the right-hand edge.
        $dpi = (int) round($dpmm * 25.4);
        $wDots = (int) round($wIn * $dpi);
        $hDots = (int) round($hIn * $dpi);
        if ($wDots > self::MAX_DOTS || $hDots > self::MAX_DOTS) {
            throw new RuntimeException('Label exceeds ' . self::MAX_DOTS . ' dots on a side.');
        }

        $renderer = new Renderer($wDots, $hDots);
        $png = $renderer->render($zpl);

        header('Content-Type: image/png');
        header('Content-Length: ' . strlen($png));
        header('Cache-Control: no-store');
        header('X-Label-Dots: ' . $wDots . 'x' . $hDots);
        // Skipped commands are surfaced in a header so the editor can show them
        // without a second request or a mixed-content response body.
        $warnings = $renderer->warnings();
        if ($warnings !== []) {
            header('X-Zpl-Warnings: ' . implode(' ', array_slice($warnings, 0, 20)));
        }
        echo $png;
    }

    /**
     * Image -> ^GF graphic field.
     *
     * Returns the ZPL rather than a picture, because the ZPL is the thing you
     * paste into a label and the thing the printer receives. The editor renders
     * it through the same preview path as everything else, so what is shown is
     * the actual field, not a separate drawing of the original image.
     */
    private function convertImage(array $query): void
    {
        $raw = (string) file_get_contents('php://input');
        if ($raw === '') {
            throw new RuntimeException('No image supplied.');
        }
        if (strlen($raw) > self::MAX_IMAGE_BYTES) {
            throw new RuntimeException('Image exceeds ' . (self::MAX_IMAGE_BYTES / 1048576) . ' MB.');
        }

        $width = isset($query['w']) ? (int) $query['w'] : null;
        $threshold = isset($query['threshold']) ? (int) $query['threshold'] : 128;
        $dither = in_array(strtolower((string) ($query['dither'] ?? '')), ['1', 'true', 'yes'], true);

        $result = (new ImageConverter())->toGraphicField($raw, $width, $threshold, $dither);

        $this->json([
            'zpl' => $result['zpl'],
            'width' => $result['width'],
            'height' => $result['height'],
            'bytes' => $result['bytes'],
            'dither' => $dither,
        ]);
    }

    private function hasFont(): bool
    {
        foreach ([
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
            '/usr/share/fonts/truetype/freefont/FreeSans.ttf',
        ] as $p) {
            if (is_file($p)) {
                return true;
            }
        }

        return false;
    }

    private function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
    }
}

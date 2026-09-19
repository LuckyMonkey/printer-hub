<?php

declare(strict_types=1);

namespace PrinterHub\Zpl;

use RuntimeException;

/**
 * Decodes a ^GF (graphic field) payload into a bitmap.
 *
 * ^GFa,b,c,d,DATA where a is the format, b the uncompressed byte count, c the
 * total field byte count and d the bytes per row. The bitmap is packed one bit
 * per pixel, most significant bit leftmost, 1 = black.
 *
 * The DATA can arrive four different ways, which is most of the work:
 *
 *   :Z64:<base64>:<crc>   base64 of ZLIB-deflated bytes. This is what Zebra's
 *                         tooling and this codebase both emit.
 *   :B64:<base64>:<crc>   base64, no compression.
 *   plain ASCII hex       two characters per byte.
 *   ASCII hex + RLE       Zebra's own run-length scheme, described below.
 *
 * The RLE is the strange one, and it is why hand-written ZPL bitmaps look like
 * line noise: G-Y mean "repeat the next hex digit 1 to 19 times", g-z mean the
 * same in twenties up to 400, and the two stack, so 'hG' is 40 + 1 = 41. Three
 * punctuation marks operate on whole rows: ',' fills the rest of the row with
 * white, '!' fills it with black, and ':' repeats the row above verbatim.
 */
final class GraphicField
{
    /**
     * @return list<list<bool>> rows of pixels, true = black
     */
    public function decode(string $format, int $bytesPerRow, int $totalBytes, string $data): array
    {
        if ($bytesPerRow < 1) {
            throw new RuntimeException('^GF needs a positive bytes-per-row.');
        }

        $binary = $this->toBinary(strtoupper($format), $bytesPerRow, $totalBytes, $data);

        $rows = [];
        $rowCount = (int) floor(strlen($binary) / $bytesPerRow);
        for ($r = 0; $r < $rowCount; $r++) {
            $row = [];
            for ($b = 0; $b < $bytesPerRow; $b++) {
                $byte = ord($binary[$r * $bytesPerRow + $b]);
                for ($bit = 7; $bit >= 0; $bit--) {
                    $row[] = (($byte >> $bit) & 1) === 1;
                }
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private function toBinary(string $format, int $bytesPerRow, int $totalBytes, string $data): string
    {
        // Strip whitespace: ZPL files wrap long graphic fields across lines and
        // the newlines are formatting, not data.
        $data = preg_replace('/\s+/', '', $data) ?? $data;

        if (str_starts_with($data, ':Z64:') || str_starts_with($data, ':B64:')) {
            return $this->decodeBase64Payload($data);
        }

        if ($format === 'B' || $format === 'C') {
            // Binary formats carry raw bytes. They cannot survive a text
            // transport unmangled, so treat them as already-decoded and let the
            // row loop take what it can.
            return $data;
        }

        return $this->decodeAsciiHex($data, $bytesPerRow, $totalBytes);
    }

    private function decodeBase64Payload(string $data): string
    {
        $scheme = substr($data, 1, 3);                    // Z64 or B64
        $rest = substr($data, 5);

        // The CRC follows a final colon. It covers the base64 text, not the
        // bytes, and is not required to decode - a mismatch is worth knowing
        // about but should not stop a preview from rendering.
        $crcPos = strrpos($rest, ':');
        $payload = $crcPos === false ? $rest : substr($rest, 0, $crcPos);

        $raw = base64_decode($payload, true);
        if ($raw === false) {
            throw new RuntimeException('^GF payload is not valid base64.');
        }

        if ($scheme === 'B64') {
            return $raw;
        }

        $inflated = @gzuncompress($raw);
        if ($inflated === false) {
            // Some encoders emit raw DEFLATE rather than ZLIB-wrapped.
            $inflated = @gzinflate($raw);
        }
        if ($inflated === false) {
            throw new RuntimeException('^GF Z64 payload could not be decompressed.');
        }

        return $inflated;
    }

    private function decodeAsciiHex(string $data, int $bytesPerRow, int $totalBytes): string
    {
        $out = '';
        $row = '';
        $previousRow = null;
        $repeat = 0;
        $len = strlen($data);

        $endRow = function (string $fill) use (&$row, &$out, &$previousRow, $bytesPerRow): void {
            $row = str_pad($row, $bytesPerRow, $fill);
            $out .= substr($row, 0, $bytesPerRow);
            $previousRow = substr($row, 0, $bytesPerRow);
            $row = '';
        };

        $nibble = null;                                   // half-built byte

        for ($i = 0; $i < $len; $i++) {
            $ch = $data[$i];

            // --- repeat counts ---
            if ($ch >= 'G' && $ch <= 'Y') {
                $repeat += ord($ch) - ord('G') + 1;
                continue;
            }
            if ($ch >= 'g' && $ch <= 'z') {
                $repeat += (ord($ch) - ord('g') + 1) * 20;
                continue;
            }

            // --- whole-row operators ---
            if ($ch === ',') {
                if ($nibble !== null) {
                    $row .= chr(hexdec($nibble . '0'));
                    $nibble = null;
                }
                $endRow("\x00");
                $repeat = 0;
                continue;
            }
            if ($ch === '!') {
                if ($nibble !== null) {
                    $row .= chr(hexdec($nibble . 'F'));
                    $nibble = null;
                }
                $endRow("\xFF");
                $repeat = 0;
                continue;
            }
            if ($ch === ':') {
                if ($previousRow === null) {
                    $endRow("\x00");
                } else {
                    $out .= $previousRow;
                }
                $row = '';
                $repeat = 0;
                continue;
            }

            // --- hex digits, honouring any pending repeat ---
            if (ctype_xdigit($ch)) {
                $times = max(1, $repeat);
                $repeat = 0;
                for ($n = 0; $n < $times; $n++) {
                    if ($nibble === null) {
                        $nibble = $ch;
                    } else {
                        $row .= chr((int) hexdec($nibble . $ch));
                        $nibble = null;
                        if (strlen($row) === $bytesPerRow) {
                            $endRow("\x00");
                        }
                    }
                }
                continue;
            }

            // Anything else is noise; ignore it rather than abandon the render.
        }

        if ($nibble !== null) {
            $row .= chr((int) hexdec($nibble . '0'));
        }
        if ($row !== '') {
            $endRow("\x00");
        }

        // Trim to the declared size when one was given and we overshot.
        if ($totalBytes > 0 && strlen($out) > $totalBytes) {
            $out = substr($out, 0, $totalBytes);
        }

        return $out;
    }
}

<?php
declare(strict_types=1);

namespace PrinterHub;

use GdImage;
use RuntimeException;

final class ZplRasterService
{
    private const LABEL_WIDTH = 812;
    private const LABEL_HEIGHT = 1218;

    /** @var list<string> */
    private const CODE128_PATTERNS = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
    ];

    /** @var array<string,string> */
    private const UPC_L_PATTERNS = [
        '0' => '0001101',
        '1' => '0011001',
        '2' => '0010011',
        '3' => '0111101',
        '4' => '0100011',
        '5' => '0110001',
        '6' => '0101111',
        '7' => '0111011',
        '8' => '0110111',
        '9' => '0001011',
    ];

    /** @var array<string,string> */
    private const UPC_R_PATTERNS = [
        '0' => '1110010',
        '1' => '1100110',
        '2' => '1101100',
        '3' => '1000010',
        '4' => '1011100',
        '5' => '1001110',
        '6' => '1010000',
        '7' => '1000100',
        '8' => '1001000',
        '9' => '1110100',
    ];

    /**
     * @param list<string> $values
     */
    public function build12UpZ64(array $values, string $symbology): string
    {
        if (!extension_loaded('gd')) {
            throw new RuntimeException('PHP GD extension is required for Zebra raster rendering.');
        }

        $symbology = strtolower(trim($symbology));
        if (!in_array($symbology, ['code128', 'upc'], true)) {
            throw new RuntimeException('Raster Z64 currently supports code128 and upc.');
        }

        $image = imagecreate(self::LABEL_WIDTH, self::LABEL_HEIGHT);
        if ($image === false) {
            throw new RuntimeException('Unable to allocate GD image for Zebra raster rendering.');
        }

        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        imagefilledrectangle($image, 0, 0, self::LABEL_WIDTH - 1, self::LABEL_HEIGHT - 1, $white);

        $this->drawCenteredText(
            image: $image,
            text: 'Zebra ZP505 4x6 - 12 Barcodes',
            x: 0,
            y: 10,
            width: self::LABEL_WIDTH,
            font: 5,
            color: $black
        );

        $xPositions = [24, 286, 548];
        $yPositions = [42, 332, 622, 912];

        foreach ($values as $index => $value) {
            $row = intdiv($index, 3);
            $col = $index % 3;
            $this->drawLabelCell(
                image: $image,
                x: $xPositions[$col],
                y: $yPositions[$row],
                value: $value,
                symbology: $symbology,
                black: $black,
                white: $white
            );
        }

        $binary = $this->packImageMonochrome($image, $black);
        imagedestroy($image);

        $bytesPerRow = (int) ceil(self::LABEL_WIDTH / 8);
        $totalBytes = strlen($binary);
        $z64 = $this->encodeZ64($binary);

        $lines = [
            '^XA',
            '^CI28',
            '^PW812',
            '^LL1218',
            '^LH0,0',
            sprintf('^FO0,0^GFA,%d,%d,%d,:Z64:%s:%s', $totalBytes, $totalBytes, $bytesPerRow, $z64['data'], $z64['crc']),
            '^XZ',
        ];

        return implode("\n", $lines) . "\n";
    }

    private function drawLabelCell(
        GdImage $image,
        int $x,
        int $y,
        string $value,
        string $symbology,
        int $black,
        int $white
    ): void {
        $barcodeTop = $y + 10;
        $barcodeHeight = 88;
        $barcodeWidth = 244;
        $labelTextTop = $y + 124;
        $cellWidth = 250;
        $safeText = $this->sanitizeText($value);

        if ($symbology === 'upc') {
            $upc = $this->normalizeUpc($safeText);
            $this->drawUpcA(
                image: $image,
                x: $x,
                y: $barcodeTop,
                maxWidth: $barcodeWidth,
                barHeight: $barcodeHeight,
                upc: $upc,
                black: $black,
                white: $white
            );
            $this->drawCenteredText($image, $upc, $x, $labelTextTop, $cellWidth, 4, $black);
            return;
        }

        $this->drawCode128B(
            image: $image,
            x: $x,
            y: $barcodeTop,
            maxWidth: $barcodeWidth,
            barHeight: $barcodeHeight,
            value: $safeText,
            black: $black,
            white: $white
        );
        $this->drawCenteredText($image, $safeText, $x, $labelTextTop, $cellWidth, 4, $black);
    }

    private function drawCode128B(
        GdImage $image,
        int $x,
        int $y,
        int $maxWidth,
        int $barHeight,
        string $value,
        int $black,
        int $white
    ): void {
        $codes = $this->encodeCode128B($value);
        $quietModules = 10;
        $totalModules = $quietModules * 2;

        foreach ($codes as $code) {
            $totalModules += strlen(self::CODE128_PATTERNS[$code]);
        }

        $moduleWidth = max(1, (int) floor($maxWidth / $totalModules));
        $barcodeWidth = $totalModules * $moduleWidth;
        $offsetX = $x + max(0, intdiv($maxWidth - $barcodeWidth, 2));

        imagefilledrectangle($image, $x, $y, $x + $maxWidth - 1, $y + $barHeight + 2, $white);

        $cursor = $offsetX + ($quietModules * $moduleWidth);
        foreach ($codes as $code) {
            $pattern = self::CODE128_PATTERNS[$code];
            $isBar = true;
            $len = strlen($pattern);

            for ($i = 0; $i < $len; $i++) {
                $w = ((int) $pattern[$i]) * $moduleWidth;
                if ($isBar) {
                    imagefilledrectangle($image, $cursor, $y, $cursor + $w - 1, $y + $barHeight, $black);
                }
                $cursor += $w;
                $isBar = !$isBar;
            }
        }
    }

    /**
     * @return list<int>
     */
    private function encodeCode128B(string $value): array
    {
        if ($value === '') {
            throw new RuntimeException('Code128 values cannot be empty.');
        }

        $codes = [104]; // Start Code B
        $checksum = 104;
        $length = strlen($value);

        for ($i = 0; $i < $length; $i++) {
            $ord = ord($value[$i]);
            if ($ord < 32 || $ord > 126) {
                throw new RuntimeException('Code128 raster mode supports ASCII 32..126.');
            }

            $code = $ord - 32;
            $codes[] = $code;
            $checksum += $code * ($i + 1);
        }

        $codes[] = $checksum % 103;
        $codes[] = 106; // Stop

        return $codes;
    }

    private function drawUpcA(
        GdImage $image,
        int $x,
        int $y,
        int $maxWidth,
        int $barHeight,
        string $upc,
        int $black,
        int $white
    ): void {
        $bits = '101';
        for ($i = 0; $i < 6; $i++) {
            $digit = $upc[$i];
            $bits .= self::UPC_L_PATTERNS[$digit];
        }
        $bits .= '01010';
        for ($i = 6; $i < 12; $i++) {
            $digit = $upc[$i];
            $bits .= self::UPC_R_PATTERNS[$digit];
        }
        $bits .= '101';

        $moduleWidth = max(1, (int) floor($maxWidth / strlen($bits)));
        $barcodeWidth = strlen($bits) * $moduleWidth;
        $offsetX = $x + max(0, intdiv($maxWidth - $barcodeWidth, 2));

        imagefilledrectangle($image, $x, $y, $x + $maxWidth - 1, $y + $barHeight + 2, $white);

        $cursor = $offsetX;
        $bitsLength = strlen($bits);
        for ($i = 0; $i < $bitsLength; $i++) {
            if ($bits[$i] === '1') {
                imagefilledrectangle($image, $cursor, $y, $cursor + $moduleWidth - 1, $y + $barHeight, $black);
            }
            $cursor += $moduleWidth;
        }
    }

    private function normalizeUpc(string $value): string
    {
        if (!preg_match('/^\d{11,12}$/', $value)) {
            throw new RuntimeException('UPC values must be 11 or 12 digits.');
        }

        if (strlen($value) === 12) {
            return $value;
        }

        return $value . (string) $this->upcCheckDigit($value);
    }

    private function upcCheckDigit(string $elevenDigits): int
    {
        $odd = 0;
        $even = 0;
        for ($i = 0; $i < 11; $i++) {
            $digit = (int) $elevenDigits[$i];
            if ($i % 2 === 0) {
                $odd += $digit;
            } else {
                $even += $digit;
            }
        }

        $sum = ($odd * 3) + $even;
        return (10 - ($sum % 10)) % 10;
    }

    private function drawCenteredText(
        GdImage $image,
        string $text,
        int $x,
        int $y,
        int $width,
        int $font,
        int $color
    ): void {
        $safe = $this->sanitizeText($text);
        $fontWidth = imagefontwidth($font);
        if ($fontWidth <= 0) {
            return;
        }

        $maxChars = max(1, intdiv($width, $fontWidth));
        if (strlen($safe) > $maxChars) {
            $safe = substr($safe, 0, $maxChars);
        }

        $textWidth = strlen($safe) * $fontWidth;
        $textX = $x + max(0, intdiv($width - $textWidth, 2));
        imagestring($image, $font, $textX, $y, $safe, $color);
    }

    private function sanitizeText(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/[[:cntrl:]]+/', ' ', $value) ?? $value;
        $value = str_replace(['^', '~'], ' ', $value);
        return $value;
    }

    private function packImageMonochrome(GdImage $image, int $blackIndex): string
    {
        $bytesPerRow = (int) ceil(self::LABEL_WIDTH / 8);
        $binary = '';

        for ($y = 0; $y < self::LABEL_HEIGHT; $y++) {
            for ($byteOffset = 0; $byteOffset < $bytesPerRow; $byteOffset++) {
                $byte = 0;
                for ($bit = 0; $bit < 8; $bit++) {
                    $byte <<= 1;
                    $x = ($byteOffset * 8) + $bit;
                    if ($x < self::LABEL_WIDTH && imagecolorat($image, $x, $y) === $blackIndex) {
                        $byte |= 1;
                    }
                }
                $binary .= chr($byte);
            }
        }

        return $binary;
    }

    /**
     * @return array{data:string,crc:string}
     */
    private function encodeZ64(string $binary): array
    {
        $compressed = gzcompress($binary, 9);
        if ($compressed === false) {
            throw new RuntimeException('Unable to compress Zebra raster image.');
        }

        $encoded = base64_encode($compressed);
        $crc = strtoupper(str_pad(dechex($this->crc16Ccitt($encoded)), 4, '0', STR_PAD_LEFT));

        return ['data' => $encoded, 'crc' => $crc];
    }

    private function crc16Ccitt(string $payload): int
    {
        $crc = 0x0000;
        $length = strlen($payload);
        for ($i = 0; $i < $length; $i++) {
            $crc ^= (ord($payload[$i]) << 8);
            for ($bit = 0; $bit < 8; $bit++) {
                if (($crc & 0x8000) !== 0) {
                    $crc = (($crc << 1) ^ 0x1021) & 0xFFFF;
                } else {
                    $crc = ($crc << 1) & 0xFFFF;
                }
            }
        }

        return $crc;
    }
}

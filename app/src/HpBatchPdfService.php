<?php
declare(strict_types=1);

namespace PrinterHub;

use GdImage;
use RuntimeException;

final class HpBatchPdfService
{
    private const PAGE_WIDTH = 1275;   // 8.5in @ 150dpi
    private const PAGE_HEIGHT = 1650;  // 11in @ 150dpi
    private const COLS = 3;
    private const ROWS = 10;

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

    public function __construct(private readonly CommandRunner $commands)
    {
    }

    /**
     * @param list<string> $values
     */
    public function buildSheetPdf(array $values, string $barcodeType, string $labelType, int $pageNumber): string
    {
        if (!extension_loaded('gd')) {
            throw new RuntimeException('PHP GD extension is required for HP barcode sheet rendering.');
        }

        $count = count($values);
        if ($count < 1 || $count > 30) {
            throw new RuntimeException('HP sheet rendering requires 1 to 30 values per page.');
        }

        $barcodeType = strtoupper(trim($barcodeType));
        if (!in_array($barcodeType, ['CODE128', 'UPCA', 'QR'], true)) {
            throw new RuntimeException('barcodeType must be CODE128, UPCA, or QR.');
        }

        $labelType = strtolower(trim($labelType));
        $title = $labelType === 'status-tag' ? 'Status Tag' : 'Waco ID';

        $image = imagecreatetruecolor(self::PAGE_WIDTH, self::PAGE_HEIGHT);
        if ($image === false) {
            throw new RuntimeException('Unable to allocate HP sheet image.');
        }

        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        $gray = imagecolorallocate($image, 170, 170, 170);
        imagefilledrectangle($image, 0, 0, self::PAGE_WIDTH - 1, self::PAGE_HEIGHT - 1, $white);

        imagestring(
            $image,
            4,
            28,
            18,
            sprintf('Printer Hub HP Sheet - %s - %s - Page %d', $title, $barcodeType, $pageNumber),
            $black
        );

        $marginX = 24;
        $marginY = 52;
        $gapX = 12;
        $gapY = 8;
        $cellWidth = intdiv(self::PAGE_WIDTH - ($marginX * 2) - ($gapX * (self::COLS - 1)), self::COLS);
        $cellHeight = intdiv(self::PAGE_HEIGHT - ($marginY * 2) - ($gapY * (self::ROWS - 1)), self::ROWS);

        foreach ($values as $index => $value) {
            $row = intdiv($index, self::COLS);
            $col = $index % self::COLS;

            $x = $marginX + ($col * ($cellWidth + $gapX));
            $y = $marginY + ($row * ($cellHeight + $gapY));

            $safe = $this->sanitize($value);
            if ($safe === '') {
                continue;
            }

            imagerectangle($image, $x, $y, $x + $cellWidth - 1, $y + $cellHeight - 1, $gray);
            imagestring($image, 2, $x + 6, $y + 6, '#' . (string) ($index + 1), $black);

            if ($barcodeType === 'QR') {
                $this->drawQrCell($image, $x, $y, $cellWidth, $cellHeight, $safe, $black);
                continue;
            }

            if ($barcodeType === 'UPCA') {
                $upc = $this->normalizeUpca($safe);
                $this->drawUpcA($image, $x + 12, $y + 22, $cellWidth - 24, $cellHeight - 70, $upc, $black, $white);
                $this->drawCenteredText($image, $upc, $x, $y + $cellHeight - 24, $cellWidth, 2, $black);
                continue;
            }

            $this->drawCode128B($image, $x + 12, $y + 22, $cellWidth - 24, $cellHeight - 70, $safe, $black, $white);
            $this->drawCenteredText($image, $safe, $x, $y + $cellHeight - 24, $cellWidth, 2, $black);
        }

        $jpeg = $this->renderJpeg($image);
        imagedestroy($image);

        $pdf = $this->buildSinglePagePdf($jpeg);

        $dir = '/tmp/printer-hub';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create /tmp/printer-hub for HP PDF jobs.');
        }

        $file = sprintf('%s/hp_sheet_%s.pdf', $dir, uniqid('', true));
        if (file_put_contents($file, $pdf) === false) {
            throw new RuntimeException('Unable to write generated HP PDF sheet.');
        }

        return $file;
    }

    private function drawQrCell(
        GdImage $page,
        int $x,
        int $y,
        int $cellWidth,
        int $cellHeight,
        string $value,
        int $black
    ): void {
        $qr = $this->buildQrImage($value);
        $qrWidth = imagesx($qr);
        $qrHeight = imagesy($qr);

        $target = min($cellWidth - 36, $cellHeight - 56);
        $target = max(80, $target);
        $targetX = $x + 12;
        $targetY = $y + 20;

        imagecopyresampled(
            $page,
            $qr,
            $targetX,
            $targetY,
            0,
            0,
            $target,
            $target,
            $qrWidth,
            $qrHeight
        );
        imagedestroy($qr);

        $this->drawCenteredText($page, $value, $x, $y + $cellHeight - 24, $cellWidth, 2, $black);
    }

    private function buildQrImage(string $value): GdImage
    {
        $tmp = sprintf('/tmp/printer-hub/qr_%s.png', uniqid('', true));

        try {
            $this->commands->mustRun([
                'qrencode',
                '-o', $tmp,
                '-t', 'PNG',
                '-s', '4',
                '-m', '1',
                $value,
            ]);
        } catch (RuntimeException $e) {
            throw new RuntimeException('QR rendering failed. Install qrencode in the container.', 0, $e);
        }

        $img = @imagecreatefrompng($tmp);
        @unlink($tmp);

        if (!$img instanceof GdImage) {
            throw new RuntimeException('Unable to decode generated QR image.');
        }

        return $img;
    }

    private function renderJpeg(GdImage $image): string
    {
        ob_start();
        imagejpeg($image, null, 88);
        $jpeg = ob_get_clean();

        if ($jpeg === false || $jpeg === '') {
            throw new RuntimeException('Failed to render HP sheet JPEG.');
        }

        return $jpeg;
    }

    private function buildSinglePagePdf(string $jpeg): string
    {
        $imageLen = strlen($jpeg);
        $content = "q\n612 0 0 792 0 0 cm\n/Im0 Do\nQ\n";

        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /XObject << /Im0 4 0 R >> >> /Contents 5 0 R >>',
            4 => sprintf(
                "<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length %d >>\nstream\n%s\nendstream",
                self::PAGE_WIDTH,
                self::PAGE_HEIGHT,
                $imageLen,
                $jpeg
            ),
            5 => sprintf("<< /Length %d >>\nstream\n%sendstream", strlen($content), $content),
        ];

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];

        for ($i = 1; $i <= 5; $i++) {
            $offsets[$i] = strlen($pdf);
            $pdf .= sprintf("%d 0 obj\n%s\nendobj\n", $i, $objects[$i]);
        }

        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 6\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= 5; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }

        $pdf .= "trailer\n<< /Size 6 /Root 1 0 R >>\n";
        $pdf .= "startxref\n" . $xrefOffset . "\n%%EOF";

        return $pdf;
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
            $bits .= self::UPC_L_PATTERNS[$upc[$i]];
        }
        $bits .= '01010';
        for ($i = 6; $i < 12; $i++) {
            $bits .= self::UPC_R_PATTERNS[$upc[$i]];
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

    /**
     * @return list<int>
     */
    private function encodeCode128B(string $value): array
    {
        if ($value === '') {
            throw new RuntimeException('Code128 values cannot be empty.');
        }

        $codes = [104];
        $checksum = 104;
        $length = strlen($value);

        for ($i = 0; $i < $length; $i++) {
            $ord = ord($value[$i]);
            if ($ord < 32 || $ord > 126) {
                throw new RuntimeException('Code128 supports ASCII 32..126.');
            }

            $code = $ord - 32;
            $codes[] = $code;
            $checksum += $code * ($i + 1);
        }

        $codes[] = $checksum % 103;
        $codes[] = 106;

        return $codes;
    }

    private function normalizeUpca(string $value): string
    {
        if (!preg_match('/^\d{11,12}$/', $value)) {
            throw new RuntimeException('UPCA values must be 11 or 12 digits.');
        }

        if (strlen($value) === 12) {
            return $value;
        }

        $odd = 0;
        $even = 0;
        for ($i = 0; $i < 11; $i++) {
            $digit = (int) $value[$i];
            if ($i % 2 === 0) {
                $odd += $digit;
            } else {
                $even += $digit;
            }
        }

        $sum = ($odd * 3) + $even;
        $checkDigit = (10 - ($sum % 10)) % 10;
        return $value . (string) $checkDigit;
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
        $safe = $this->sanitize($text);
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

    private function sanitize(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/[\x00-\x1F\x7F]/', '', $value) ?? '';
        $value = str_replace(['^', '~'], ' ', $value);
        return $value;
    }
}

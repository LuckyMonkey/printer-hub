<?php
declare(strict_types=1);

namespace PrinterHub;

use RuntimeException;

final class JobService
{
    private const TEMPLATE_ZEBRA_4X6 = 'zebra_4x6';
    private const TEMPLATE_ZEBRA_SMALL = 'zebra_2_4x1_1';
    private const TEMPLATE_AVERY_SHEET = 'avery_3x10';
    private const TEMPLATE_SINGLE_SMALL = 'single_2_4x1_1';

    public function __construct(private readonly CommandRunner $commands)
    {
    }

    /**
     * @return array{submitted:bool,jobOutput:string,file:string,template:string}
     */
    public function submit(array $payload): array
    {
        $printer = trim((string) ($payload['printer'] ?? ''));
        $template = trim((string) ($payload['template'] ?? ''));
        $symbology = trim((string) ($payload['symbology'] ?? 'code128'));
        $copies = (int) ($payload['copies'] ?? 1);
        $title = trim((string) ($payload['title'] ?? 'printer-hub-job'));

        if ($printer === '') {
            throw new RuntimeException('Printer is required.');
        }

        if (!in_array($template, [
            self::TEMPLATE_ZEBRA_4X6,
            self::TEMPLATE_ZEBRA_SMALL,
            self::TEMPLATE_AVERY_SHEET,
            self::TEMPLATE_SINGLE_SMALL,
        ], true)) {
            throw new RuntimeException('Unknown template selected.');
        }

        if (!in_array($symbology, ['code128', 'qr', 'upc'], true)) {
            throw new RuntimeException('Symbology must be code128, qr, or upc.');
        }

        $values = $this->normalizeValues($payload);
        if ($values === []) {
            throw new RuntimeException('At least one barcode value is required.');
        }

        if ($copies < 1 || $copies > 250) {
            throw new RuntimeException('Copies must be between 1 and 250.');
        }

        $tmpDir = '/tmp/printer-hub';
        if (!is_dir($tmpDir) && !mkdir($tmpDir, 0775, true) && !is_dir($tmpDir)) {
            throw new RuntimeException('Unable to create temporary print directory.');
        }

        if ($template === self::TEMPLATE_ZEBRA_4X6 || $template === self::TEMPLATE_ZEBRA_SMALL) {
            $zpl = $this->buildZplDocument($values, $symbology, $template);
            $file = sprintf('%s/%s.zpl', $tmpDir, uniqid('job_', true));
            file_put_contents($file, $zpl);

            $out = $this->commands->mustRun([
                'sudo',
                'lp',
                '-d', $printer,
                '-t', $title,
                '-n', (string) $copies,
                '-o', 'raw',
                $file,
            ]);

            return [
                'submitted' => true,
                'jobOutput' => $out,
                'file' => $file,
                'template' => $template,
            ];
        }

        $textFile = sprintf('%s/%s.txt', $tmpDir, uniqid('job_', true));
        file_put_contents($textFile, $this->buildTextDocument($template, $symbology, $values));

        $out = $this->commands->mustRun([
            'sudo',
            'lp',
            '-d', $printer,
            '-t', $title,
            '-n', (string) $copies,
            $textFile,
        ]);

        return [
            'submitted' => true,
            'jobOutput' => $out,
            'file' => $textFile,
            'template' => $template,
        ];
    }

    /**
     * @return list<array{id:string,printer:string,file:string,status:string}>
     */
    public function listQueue(): array
    {
        $stdout = $this->commands->mustRun(['sudo', 'lpstat', '-W', 'not-completed', '-o']);

        $jobs = [];
        foreach (preg_split('/\R+/', $stdout) as $line) {
            if ($line === '') {
                continue;
            }

            if (preg_match('/^([^\s]+)-(\d+)\s+([^\s]+)\s+([^\s]+)\s+(.+)$/', $line, $m)) {
                $jobs[] = [
                    'id' => $m[1] . '-' . $m[2],
                    'printer' => $m[1],
                    'file' => $m[4],
                    'status' => trim($m[5]),
                ];
            } else {
                $jobs[] = [
                    'id' => md5($line),
                    'printer' => 'unknown',
                    'file' => $line,
                    'status' => 'pending',
                ];
            }
        }

        return $jobs;
    }

    /**
     * @return list<string>
     */
    private function normalizeValues(array $payload): array
    {
        $values = $payload['values'] ?? [];
        if (is_string($values)) {
            $values = preg_split('/\R+/', $values) ?: [];
        }

        if (!is_array($values)) {
            $single = trim((string) ($payload['value'] ?? ''));
            return $single === '' ? [] : [$single];
        }

        $normalized = [];
        foreach ($values as $value) {
            $clean = trim((string) $value);
            if ($clean !== '') {
                $normalized[] = $clean;
            }
        }

        if ($normalized === []) {
            $single = trim((string) ($payload['value'] ?? ''));
            if ($single !== '') {
                $normalized[] = $single;
            }
        }

        return $normalized;
    }

    /**
     * @param list<string> $values
     */
    private function buildZplDocument(array $values, string $symbology, string $template): string
    {
        $parts = [];
        foreach ($values as $value) {
            $parts[] = $this->buildZplSingle($value, $symbology, $template);
        }

        return implode("\n", $parts) . "\n";
    }

    private function buildZplSingle(string $value, string $symbology, string $template): string
    {
        $safeValue = preg_replace('/[^\x20-\x7E]/', '', $value) ?? '';
        $safeValue = str_replace(['^', '~'], [' ', ' '], $safeValue);

        if ($safeValue === '') {
            throw new RuntimeException('Barcode value may only include printable characters.');
        }

        if ($template === self::TEMPLATE_ZEBRA_4X6) {
            $header = "^XA\n^CI28\n^PW812\n^LL1218\n^LH0,0";
            $footer = '^XZ';

            if ($symbology === 'code128') {
                return implode("\n", [
                    $header,
                    '^FO70,80^A0N,46,46^FDFridge Printer Hub^FS',
                    '^BY3,2,180',
                    '^FO90,260^BCN,180,Y,N,N^FD' . $safeValue . '^FS',
                    '^FO90,520^A0N,44,44^FD' . $safeValue . '^FS',
                    $footer,
                ]);
            }

            return implode("\n", [
                $header,
                '^FO70,80^A0N,46,46^FDFridge Printer Hub^FS',
                '^FO120,200^BQN,2,10^FDLA,' . $safeValue . '^FS',
                '^FO120,620^A0N,40,40^FD' . $safeValue . '^FS',
                $footer,
            ]);
        }

        $header = "^XA\n^CI28\n^PW576\n^LL264\n^LH0,0";
        $footer = '^XZ';

        if ($symbology === 'code128') {
            return implode("\n", [
                $header,
                '^BY2,2,90',
                '^FO20,28^BCN,90,Y,N,N^FD' . $safeValue . '^FS',
                '^FO20,190^A0N,28,28^FD' . $safeValue . '^FS',
                $footer,
            ]);
        }

        return implode("\n", [
            $header,
            '^FO20,20^BQN,2,5^FDLA,' . $safeValue . '^FS',
            '^FO170,95^A0N,24,24^FD' . $safeValue . '^FS',
            $footer,
        ]);
    }

    /**
     * @param list<string> $values
     */
    private function buildTextDocument(string $template, string $symbology, array $values): string
    {
        $lines = [
            'Printer Hub',
            sprintf('Template: %s', $template),
            sprintf('Symbology: %s', strtoupper($symbology)),
            sprintf('Count: %d', count($values)),
            '',
        ];

        foreach ($values as $index => $value) {
            $lines[] = sprintf('%d. %s', $index + 1, $value);
        }

        return implode("\n", $lines) . "\n";
    }
}

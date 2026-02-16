<?php
declare(strict_types=1);

namespace PrinterHub;

use RuntimeException;

final class PrinterService
{
    public function __construct(private readonly CommandRunner $commands)
    {
    }

    /**
     * @return array{defaultPrinter:?string,printers:list<array{name:string,status:string,device:?string}>}
     */
    public function listPrinters(): array
    {
        $printerResult = $this->commands->run(['sudo', 'lpstat', '-p', '-d']);
        if (
            $printerResult['code'] !== 0
            && !str_contains($printerResult['stderr'], 'No destinations added')
        ) {
            throw new RuntimeException(sprintf(
                'Unable to query printers: %s',
                $printerResult['stderr'] !== '' ? $printerResult['stderr'] : 'unknown error'
            ));
        }

        $deviceResult = $this->commands->run(['sudo', 'lpstat', '-v']);
        if (
            $deviceResult['code'] !== 0
            && !str_contains($deviceResult['stderr'], 'No destinations added')
        ) {
            throw new RuntimeException(sprintf(
                'Unable to query printer devices: %s',
                $deviceResult['stderr'] !== '' ? $deviceResult['stderr'] : 'unknown error'
            ));
        }

        $printerOutput = $printerResult['stdout'];
        $deviceOutput = $deviceResult['stdout'];

        $defaultPrinter = null;
        $printers = [];

        foreach (preg_split('/\R+/', $printerOutput) as $line) {
            if ($line === '' || str_starts_with($line, 'scheduler is not running')) {
                continue;
            }

            if (preg_match('/^system default destination: (.+)$/', $line, $m)) {
                $defaultPrinter = trim($m[1]);
                continue;
            }

            if (preg_match('/^printer\s+([^\s]+)\s+(.+)$/', $line, $m)) {
                $printers[$m[1]] = [
                    'name' => $m[1],
                    'status' => trim($m[2]),
                    'device' => null,
                ];
            }
        }

        foreach (preg_split('/\R+/', $deviceOutput) as $line) {
            if (preg_match('/^device for ([^:]+):\s*(.+)$/', $line, $m)) {
                if (!isset($printers[$m[1]])) {
                    $printers[$m[1]] = [
                        'name' => $m[1],
                        'status' => 'unknown',
                        'device' => trim($m[2]),
                    ];
                    continue;
                }
                $printers[$m[1]]['device'] = trim($m[2]);
            }
        }

        ksort($printers);

        return [
            'defaultPrinter' => $defaultPrinter,
            'printers' => array_values($printers),
        ];
    }

    /**
     * @return array{name:string,uri:string,model:string}
     */
    public function addOrUpdatePrinter(array $payload): array
    {
        $name = trim((string) ($payload['name'] ?? ''));
        $uri = trim((string) ($payload['uri'] ?? ''));
        $model = trim((string) ($payload['model'] ?? 'everywhere'));
        $setDefault = (bool) ($payload['setDefault'] ?? false);

        if ($name === '' || !preg_match('/^[A-Za-z0-9_.-]+$/', $name)) {
            throw new RuntimeException('Printer name is required and may only use letters, numbers, dot, dash, or underscore.');
        }

        if ($uri === '') {
            throw new RuntimeException('Printer URI is required. Example: socket://192.168.1.20:9100');
        }

        $this->commands->mustRun(['sudo', 'lpadmin', '-p', $name, '-E', '-v', $uri, '-m', $model]);
        $this->commands->mustRun(['sudo', 'cupsenable', $name]);
        $this->commands->mustRun(['sudo', 'accept', $name]);

        if ($setDefault) {
            $this->commands->mustRun(['sudo', 'lpadmin', '-d', $name]);
        }

        return [
            'name' => $name,
            'uri' => $uri,
            'model' => $model,
        ];
    }
}

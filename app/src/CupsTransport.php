<?php
declare(strict_types=1);

namespace PrinterHub;

use RuntimeException;

final class CupsTransport
{
    public function __construct(private readonly CommandRunner $commands)
    {
    }

    /** @return array{jobOutput:string,queue:string,mode:string,file:string} */
    public function sendRawBytes(string $queue, string $bytes, string $title, int $copies): array
    {
        $this->ensureQueueExists($queue);

        $file = $this->writeTempFile('raw', '.bin', $bytes);
        $out = $this->commands->mustRun([
            'sudo',
            'lp',
            '-d', $queue,
            '-t', $title,
            '-n', (string) $copies,
            '-o', 'raw',
            $file,
        ]);

        return [
            'jobOutput' => $out,
            'queue' => $queue,
            'mode' => 'raw',
            'file' => $file,
        ];
    }

    /** @return array{jobOutput:string,queue:string,mode:string,file:string} */
    public function sendText(string $queue, string $text, string $title, int $copies): array
    {
        $this->ensureQueueExists($queue);

        $file = $this->writeTempFile('text', '.txt', $text);
        $out = $this->commands->mustRun([
            'sudo',
            'lp',
            '-d', $queue,
            '-t', $title,
            '-n', (string) $copies,
            $file,
        ]);

        return [
            'jobOutput' => $out,
            'queue' => $queue,
            'mode' => 'normal',
            'file' => $file,
        ];
    }

    /** @return array{jobOutput:string,queue:string,mode:string,file:string} */
    public function sendFile(string $queue, string $file, string $title, int $copies, bool $raw = false): array
    {
        $this->ensureQueueExists($queue);

        $path = trim($file);
        if ($path === '' || !is_file($path)) {
            throw new RuntimeException(sprintf('Print file does not exist: %s', $file));
        }

        $command = [
            'sudo',
            'lp',
            '-d', $queue,
            '-t', $title,
            '-n', (string) $copies,
        ];

        if ($raw) {
            $command[] = '-o';
            $command[] = 'raw';
        }

        $command[] = $path;

        $out = $this->commands->mustRun($command);

        return [
            'jobOutput' => $out,
            'queue' => $queue,
            'mode' => $raw ? 'raw' : 'normal',
            'file' => $path,
        ];
    }

    private function writeTempFile(string $prefix, string $suffix, string $contents): string
    {
        $tmpDir = '/tmp/printer-hub';
        if (!is_dir($tmpDir) && !mkdir($tmpDir, 0775, true) && !is_dir($tmpDir)) {
            throw new RuntimeException('Unable to create /tmp/printer-hub for CUPS jobs.');
        }

        $file = sprintf('%s/%s_%s%s', $tmpDir, $prefix, uniqid('', true), $suffix);
        if (file_put_contents($file, $contents) === false) {
            throw new RuntimeException('Unable to write temporary CUPS job file.');
        }

        return $file;
    }

    private function ensureQueueExists(string $queue): void
    {
        $queue = trim($queue);
        if ($queue === '') {
            throw new RuntimeException('CUPS queue name is empty.');
        }

        $probe = $this->commands->run(['sudo', 'lpstat', '-p', $queue]);
        if ($probe['code'] === 0) {
            return;
        }

        $all = $this->commands->run(['sudo', 'lpstat', '-p']);
        $available = [];
        if ($all['code'] === 0 && $all['stdout'] !== '') {
            foreach (preg_split('/\R+/', $all['stdout']) as $line) {
                if (preg_match('/^printer\s+([^\s]+)\s+/', (string) $line, $m)) {
                    $available[] = $m[1];
                }
            }
        }

        $availableText = $available === []
            ? 'none'
            : implode(', ', $available);

        throw new RuntimeException(sprintf(
            'CUPS queue "%s" does not exist. Available queues: %s. Add it via /api/printers/add or lpadmin.',
            $queue,
            $availableText
        ));
    }
}

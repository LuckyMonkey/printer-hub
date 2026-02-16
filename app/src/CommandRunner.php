<?php
declare(strict_types=1);

namespace PrinterHub;

use RuntimeException;

final class CommandRunner
{
    /**
     * @param list<string> $command
     * @return array{code:int,stdout:string,stderr:string}
     */
    public function run(array $command, ?string $stdin = null): array
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            $command,
            $descriptorSpec,
            $pipes,
            null,
            null,
            ['bypass_shell' => true]
        );

        if (!is_resource($process)) {
            throw new RuntimeException('Failed to start command process.');
        }

        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $code = proc_close($process);

        return [
            'code' => $code,
            'stdout' => trim((string) $stdout),
            'stderr' => trim((string) $stderr),
        ];
    }

    /**
     * @param list<string> $command
     */
    public function mustRun(array $command, ?string $stdin = null): string
    {
        $result = $this->run($command, $stdin);
        if ($result['code'] !== 0) {
            throw new RuntimeException(sprintf(
                'Command failed (%s): %s',
                implode(' ', $command),
                $result['stderr'] !== '' ? $result['stderr'] : 'unknown error'
            ));
        }

        return $result['stdout'];
    }
}

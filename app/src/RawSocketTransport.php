<?php
declare(strict_types=1);

namespace PrinterHub;

use RuntimeException;

final class RawSocketTransport
{
    /**
     * @return array{bytesSent:int,host:string,port:int}
     */
    public function send(string $host, int $port, string $bytes, float $timeoutSeconds): array
    {
        if ($host === '') {
            throw new RuntimeException('Socket host is required.');
        }

        if ($port < 1 || $port > 65535) {
            throw new RuntimeException('Socket port must be between 1 and 65535.');
        }

        $errno = 0;
        $errstr = '';

        $socket = @fsockopen($host, $port, $errno, $errstr, $timeoutSeconds);
        if (!is_resource($socket)) {
            throw new RuntimeException(sprintf('Socket connection failed (%s:%d): %s (%d)', $host, $port, $errstr, $errno));
        }

        try {
            stream_set_timeout($socket, (int) ceil($timeoutSeconds));
            $total = strlen($bytes);
            $sent = 0;

            while ($sent < $total) {
                $written = fwrite($socket, substr($bytes, $sent));
                if ($written === false) {
                    throw new RuntimeException(sprintf('Socket write failed after %d of %d bytes.', $sent, $total));
                }

                if ($written === 0) {
                    $meta = stream_get_meta_data($socket);
                    if (($meta['timed_out'] ?? false) === true) {
                        throw new RuntimeException(sprintf('Socket write timed out after %d of %d bytes.', $sent, $total));
                    }

                    throw new RuntimeException(sprintf('Socket write made no progress after %d of %d bytes.', $sent, $total));
                }

                $sent += $written;
            }
        } finally {
            fclose($socket);
        }

        return [
            'bytesSent' => strlen($bytes),
            'host' => $host,
            'port' => $port,
        ];
    }

    /** @return array{ok:bool,error:?string} */
    public function ping(string $host, int $port, float $timeoutSeconds): array
    {
        $errno = 0;
        $errstr = '';
        $socket = @fsockopen($host, $port, $errno, $errstr, $timeoutSeconds);
        if (!is_resource($socket)) {
            return [
                'ok' => false,
                'error' => sprintf('%s (%d)', $errstr, $errno),
            ];
        }

        fclose($socket);

        return [
            'ok' => true,
            'error' => null,
        ];
    }
}

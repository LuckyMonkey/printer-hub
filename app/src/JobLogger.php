<?php
declare(strict_types=1);

namespace PrinterHub;

final class JobLogger
{
    public function __construct(private readonly string $logPath)
    {
    }

    /** @param array<string,mixed> $context */
    public function log(string $jobId, string $event, array $context = []): void
    {
        $dir = dirname($this->logPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $payload = [
            'timestamp' => gmdate('c'),
            'jobId' => $jobId,
            'event' => $event,
            'context' => $context,
        ];

        @file_put_contents($this->logPath, json_encode($payload) . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}

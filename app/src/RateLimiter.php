<?php
declare(strict_types=1);

namespace PrinterHub;

final class RateLimiter
{
    public function __construct(private readonly string $stateFile = '/tmp/printer-hub/rate-limiter.json')
    {
    }

    public function allow(string $key, int $maxRequests, int $windowSeconds = 60): bool
    {
        $maxRequests = max(1, $maxRequests);
        $windowSeconds = max(1, $windowSeconds);

        $dir = dirname($this->stateFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $handle = @fopen($this->stateFile, 'c+');
        if ($handle === false) {
            // Fail-open so print operations are not blocked if filesystem has issues.
            return true;
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                return true;
            }

            $raw = stream_get_contents($handle);
            $state = [];
            if (is_string($raw) && trim($raw) !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $state = $decoded;
                }
            }

            $now = time();
            $windowStart = $now - $windowSeconds;

            $entries = [];
            if (isset($state[$key]) && is_array($state[$key])) {
                foreach ($state[$key] as $ts) {
                    if (is_int($ts) && $ts >= $windowStart) {
                        $entries[] = $ts;
                    }
                }
            }

            if (count($entries) >= $maxRequests) {
                return false;
            }

            $entries[] = $now;
            $state[$key] = $entries;

            // Compact stale keys.
            foreach ($state as $stateKey => $timestamps) {
                if (!is_array($timestamps)) {
                    unset($state[$stateKey]);
                    continue;
                }

                $kept = [];
                foreach ($timestamps as $ts) {
                    if (is_int($ts) && $ts >= $windowStart) {
                        $kept[] = $ts;
                    }
                }

                if ($kept === []) {
                    unset($state[$stateKey]);
                } else {
                    $state[$stateKey] = $kept;
                }
            }

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($state));

            return true;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}

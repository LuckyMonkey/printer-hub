<?php
declare(strict_types=1);

namespace PrinterHub;

use RuntimeException;

final class ApiController
{
    public function __construct(
        private readonly PrinterService $printers,
        private readonly JobService $jobs,
        private readonly PrintWorkflowService $workflow,
        private readonly MultiPrinterPrintService $multiPrint,
        private readonly RateLimiter $rateLimiter,
        private readonly PrinterRegistry $registry,
        private readonly ?SeriesPrintService $seriesPrint = null,
        private readonly ?BatchPrintService $batchPrint = null
    ) {
    }

    /**
     * @param array<string,mixed> $query
     */
    public function handle(string $method, string $path, array $query = []): void
    {
        try {
            if ($method === 'GET' && $path === '/api/health') {
                $this->respond($this->workflow->health());
                return;
            }

            if ($method === 'GET' && $path === '/api/config') {
                $legacy = $this->workflow->config();
                $legacy['multiPrinter'] = $this->multiPrint->config();
                $this->respond($legacy);
                return;
            }

            if ($method === 'GET' && $path === '/api/print/config') {
                $this->respond($this->multiPrint->config());
                return;
            }

            if ($method === 'GET' && $path === '/api/printers') {
                $this->respond($this->printers->listPrinters());
                return;
            }

            if ($method === 'POST' && $path === '/api/printers/add') {
                $payload = $this->jsonBody();
                $this->respond(['saved' => $this->printers->addOrUpdatePrinter($payload)]);
                return;
            }

            if ($method === 'GET' && $path === '/api/queue') {
                $this->respond(['jobs' => $this->jobs->listQueue()]);
                return;
            }

            if ($method === 'GET' && $path === '/api/batches') {
                $printer = isset($query['printer']) ? trim((string) $query['printer']) : null;
                $limit = isset($query['limit']) ? (int) $query['limit'] : 40;
                $this->respond(['batches' => $this->workflow->listBatches($printer, $limit)]);
                return;
            }

            if ($method === 'POST' && $path === '/api/batches/save-print-early') {
                if ($this->batchPrint === null) {
                    throw new RuntimeException('Batch early print service is unavailable.');
                }

                $this->enforceRateLimit();
                $payload = $this->jsonBody();
                $this->respond($this->batchPrint->saveAndPrintEarly($payload), 202);
                return;
            }

            if ($method === 'POST' && $path === '/api/print/brother') {
                $payload = $this->jsonBody();
                $this->respond($this->workflow->submitBrother($payload));
                return;
            }

            if ($method === 'POST' && $path === '/api/print/zebra') {
                $payload = $this->jsonBody();
                $this->respond($this->workflow->submitZebra($payload));
                return;
            }

            if ($method === 'POST' && $path === '/api/print/hp') {
                $payload = $this->jsonBody();
                $this->respond($this->workflow->submitHp($payload));
                return;
            }

            if ($method === 'POST' && $path === '/api/print/diagnostics/brother') {
                $this->enforceRateLimit();
                $payload = $this->jsonBody();
                $this->respond($this->multiPrint->brotherDiagnostics($payload));
                return;
            }

            if ($method === 'POST' && $path === '/api/print') {
                $this->enforceRateLimit();
                $payload = $this->jsonBody();
                $this->respond($this->multiPrint->submit($payload), 202);
                return;
            }

            if ($method === 'GET' && preg_match('#^/api/print/([a-f0-9]{16})$#', $path, $m)) {
                $this->respond($this->multiPrint->status($m[1]));
                return;
            }

            if ($method === 'POST' && $path === '/api/series/print') {
                if ($this->seriesPrint === null) {
                    throw new RuntimeException('Series print service is unavailable.');
                }

                $this->enforceRateLimit();
                $payload = $this->jsonBody();
                $this->respond($this->seriesPrint->printSeries($payload), 202);
                return;
            }

            if ($method === 'GET' && $path === '/api/series') {
                if ($this->seriesPrint === null) {
                    throw new RuntimeException('Series print service is unavailable.');
                }

                $limit = isset($query['limit']) ? (int) $query['limit'] : 200;
                $this->respond(['series' => $this->seriesPrint->listSeries($limit)]);
                return;
            }

            if ($method === 'POST' && $path === '/api/jobs') {
                // Backward compatibility for existing generic UI.
                $payload = $this->jsonBody();
                $this->respond($this->jobs->submit($payload));
                return;
            }

            $this->respond(['error' => 'Not Found'], 404);
        } catch (RuntimeException $e) {
            $this->respond(['error' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            $this->respond(['error' => 'Server error', 'detail' => $e->getMessage()], 500);
        }
    }

    /** @return array<string,mixed> */
    private function jsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid JSON payload.');
        }

        return $decoded;
    }

    /** @param array<string,mixed> $payload */
    private function respond(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Access-Control-Allow-Methods: GET,POST,OPTIONS');
        echo json_encode($payload, JSON_PRETTY_PRINT);
    }

    private function enforceRateLimit(): void
    {
        $ip = $this->clientIp();
        $limit = $this->registry->rateLimitPerMinute();
        $allowed = $this->rateLimiter->allow('api-print:' . $ip, $limit, 60);
        if (!$allowed) {
            throw new RuntimeException('Rate limit exceeded. Please retry in a minute.');
        }
    }

    private function clientIp(): string
    {
        $forwarded = trim((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
        if ($forwarded !== '') {
            $parts = explode(',', $forwarded);
            $candidate = trim($parts[0]);
            if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                return $candidate;
            }
        }

        $remote = trim((string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'));
        if (filter_var($remote, FILTER_VALIDATE_IP) !== false) {
            return $remote;
        }

        return '127.0.0.1';
    }
}

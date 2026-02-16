<?php
declare(strict_types=1);

namespace PrinterHub;

final class SheetsBackupService
{
    public function __construct(private readonly ?string $webhookUrl)
    {
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{status:string,response:?string,httpCode:int}
     */
    public function backup(array $payload): array
    {
        $url = trim((string) ($this->webhookUrl ?? ''));
        if ($url === '') {
            return [
                'status' => 'not_configured',
                'response' => null,
                'httpCode' => 0,
            ];
        }

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return [
                'status' => 'json_error',
                'response' => 'Failed to encode backup payload.',
                'httpCode' => 0,
            ];
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $json,
                'timeout' => 8,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        $response = $response === false ? null : trim($response);

        $httpCode = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $httpCode = (int) $m[1];
        }

        $status = $httpCode >= 200 && $httpCode < 300 ? 'ok' : 'error';

        return [
            'status' => $status,
            'response' => $response,
            'httpCode' => $httpCode,
        ];
    }
}

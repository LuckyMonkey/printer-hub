<?php
declare(strict_types=1);

namespace PrinterHub;

use PDO;

final class PrintJobRepository
{
    private bool $schemaReady = false;

    public function __construct(private readonly Database $database)
    {
    }

    public function ensureSchema(): void
    {
        if ($this->schemaReady) {
            return;
        }

        $pdo = $this->database->pdo();

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS print_jobs (
                job_id TEXT PRIMARY KEY,
                printer_id TEXT NOT NULL,
                label_type TEXT NOT NULL,
                barcode_type TEXT NOT NULL,
                barcode_value TEXT NOT NULL,
                text_line1 TEXT,
                copies INTEGER NOT NULL,
                status TEXT NOT NULL,
                error_message TEXT,
                payload_summary JSONB NOT NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )"
        );

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_print_jobs_created ON print_jobs(created_at DESC)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_print_jobs_printer_created ON print_jobs(printer_id, created_at DESC)');

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS print_job_errors (
                id BIGSERIAL PRIMARY KEY,
                printer_id TEXT NOT NULL,
                job_id TEXT,
                error_message TEXT NOT NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )"
        );

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_print_job_errors_printer_created ON print_job_errors(printer_id, created_at DESC)');

        $this->schemaReady = true;
    }

    /** @param array<string,mixed> $summary */
    public function createJob(string $jobId, string $printerId, string $labelType, array $summary): void
    {
        $this->ensureSchema();

        $stmt = $this->database->pdo()->prepare(
            'INSERT INTO print_jobs (
                job_id, printer_id, label_type, barcode_type, barcode_value, text_line1, copies,
                status, payload_summary
            ) VALUES (
                :job_id, :printer_id, :label_type, :barcode_type, :barcode_value, :text_line1, :copies,
                :status, :payload_summary::jsonb
            )'
        );

        $stmt->execute([
            ':job_id' => $jobId,
            ':printer_id' => $printerId,
            ':label_type' => $labelType,
            ':barcode_type' => (string) ($summary['barcodeType'] ?? ''),
            ':barcode_value' => (string) ($summary['barcodeValue'] ?? ''),
            ':text_line1' => (string) ($summary['textLine1'] ?? ''),
            ':copies' => (int) ($summary['copies'] ?? 1),
            ':status' => 'queued',
            ':payload_summary' => json_encode($summary, JSON_THROW_ON_ERROR),
        ]);
    }

    public function updateStatus(string $jobId, string $status, ?string $errorMessage = null): void
    {
        $this->ensureSchema();

        $stmt = $this->database->pdo()->prepare(
            'UPDATE print_jobs
             SET status = :status,
                 error_message = :error,
                 updated_at = NOW()
             WHERE job_id = :job_id'
        );

        $stmt->execute([
            ':status' => $status,
            ':error' => $errorMessage,
            ':job_id' => $jobId,
        ]);
    }

    /** @return array<string,mixed>|null */
    public function getJob(string $jobId): ?array
    {
        $this->ensureSchema();

        $stmt = $this->database->pdo()->prepare(
            'SELECT job_id, printer_id, label_type, barcode_type, barcode_value, text_line1, copies,
                    status, error_message, payload_summary, created_at, updated_at
             FROM print_jobs
             WHERE job_id = :job_id'
        );
        $stmt->execute([':job_id' => $jobId]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        $summary = [];
        if (is_string($row['payload_summary'] ?? null)) {
            $decoded = json_decode((string) $row['payload_summary'], true);
            if (is_array($decoded)) {
                $summary = $decoded;
            }
        }

        return [
            'jobId' => (string) $row['job_id'],
            'printerId' => (string) $row['printer_id'],
            'labelType' => (string) $row['label_type'],
            'barcodeType' => (string) $row['barcode_type'],
            'barcodeValue' => (string) $row['barcode_value'],
            'textLine1' => (string) ($row['text_line1'] ?? ''),
            'copies' => (int) $row['copies'],
            'status' => (string) $row['status'],
            'error' => $row['error_message'] !== null ? (string) $row['error_message'] : null,
            'payloadSummary' => $summary,
            'createdAt' => (string) $row['created_at'],
            'updatedAt' => (string) $row['updated_at'],
        ];
    }

    public function recordError(string $printerId, ?string $jobId, string $errorMessage, int $historyLimit): void
    {
        $this->ensureSchema();

        $pdo = $this->database->pdo();
        $insert = $pdo->prepare(
            'INSERT INTO print_job_errors (printer_id, job_id, error_message)
             VALUES (:printer_id, :job_id, :error_message)'
        );

        $insert->execute([
            ':printer_id' => $printerId,
            ':job_id' => $jobId,
            ':error_message' => $errorMessage,
        ]);

        $historyLimit = max(5, min(500, $historyLimit));

        $delete = $pdo->prepare(
            'DELETE FROM print_job_errors
             WHERE printer_id = :printer_id
               AND id IN (
                 SELECT id
                 FROM print_job_errors
                 WHERE printer_id = :printer_id_inner
                 ORDER BY created_at DESC
                 OFFSET :offset_keep
               )'
        );

        $delete->bindValue(':printer_id', $printerId);
        $delete->bindValue(':printer_id_inner', $printerId);
        $delete->bindValue(':offset_keep', $historyLimit, PDO::PARAM_INT);
        $delete->execute();
    }

    /** @return list<array<string,mixed>> */
    public function recentErrors(string $printerId, int $limit = 10): array
    {
        $this->ensureSchema();
        $limit = max(1, min($limit, 100));

        $stmt = $this->database->pdo()->prepare(
            'SELECT printer_id, job_id, error_message, created_at
             FROM print_job_errors
             WHERE printer_id = :printer_id
             ORDER BY created_at DESC
             LIMIT :limit_rows'
        );

        $stmt->bindValue(':printer_id', $printerId);
        $stmt->bindValue(':limit_rows', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(
            static fn(array $row): array => [
                'printerId' => (string) $row['printer_id'],
                'jobId' => $row['job_id'] !== null ? (string) $row['job_id'] : null,
                'error' => (string) $row['error_message'],
                'createdAt' => (string) $row['created_at'],
            ],
            $rows
        );
    }
}

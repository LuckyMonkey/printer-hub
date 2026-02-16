<?php
declare(strict_types=1);

namespace PrinterHub;

use PDO;

final class SeriesBarcodeRepository
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
            "CREATE TABLE IF NOT EXISTS series_printed_barcodes (
                id BIGSERIAL PRIMARY KEY,
                barcode_hash TEXT NOT NULL UNIQUE,
                barcode_value TEXT NOT NULL,
                name TEXT,
                expiration TEXT,
                brand TEXT,
                home TEXT,
                note TEXT,
                series_key TEXT NOT NULL,
                printer_id TEXT NOT NULL,
                label_type TEXT NOT NULL,
                job_id TEXT,
                print_status TEXT NOT NULL,
                error_message TEXT,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )"
        );

        // Backward-compatible migrations if table already exists from older schema.
        $pdo->exec('ALTER TABLE series_printed_barcodes ADD COLUMN IF NOT EXISTS name TEXT');
        $pdo->exec('ALTER TABLE series_printed_barcodes ADD COLUMN IF NOT EXISTS expiration TEXT');
        $pdo->exec('ALTER TABLE series_printed_barcodes ADD COLUMN IF NOT EXISTS brand TEXT');
        $pdo->exec('ALTER TABLE series_printed_barcodes ADD COLUMN IF NOT EXISTS home TEXT');
        $pdo->exec('ALTER TABLE series_printed_barcodes ADD COLUMN IF NOT EXISTS note TEXT');

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_series_printed_created ON series_printed_barcodes(created_at DESC)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_series_printed_series_key ON series_printed_barcodes(series_key, created_at DESC)');

        $this->schemaReady = true;
    }

    /**
     * @return array{inserted:bool,id:?int}
     */
    public function insertIfNew(
        string $barcodeHash,
        string $barcodeValue,
        ?string $name,
        ?string $expiration,
        ?string $brand,
        ?string $home,
        ?string $note,
        string $seriesKey,
        string $printerId,
        string $labelType,
        ?string $jobId,
        string $printStatus,
        ?string $errorMessage
    ): array {
        $this->ensureSchema();

        $stmt = $this->database->pdo()->prepare(
            'INSERT INTO series_printed_barcodes (
                barcode_hash,
                barcode_value,
                name,
                expiration,
                brand,
                home,
                note,
                series_key,
                printer_id,
                label_type,
                job_id,
                print_status,
                error_message
            ) VALUES (
                :barcode_hash,
                :barcode_value,
                :name,
                :expiration,
                :brand,
                :home,
                :note,
                :series_key,
                :printer_id,
                :label_type,
                :job_id,
                :print_status,
                :error_message
            )
            ON CONFLICT (barcode_hash) DO NOTHING
            RETURNING id'
        );

        $stmt->execute([
            ':barcode_hash' => $barcodeHash,
            ':barcode_value' => $barcodeValue,
            ':name' => $name,
            ':expiration' => $expiration,
            ':brand' => $brand,
            ':home' => $home,
            ':note' => $note,
            ':series_key' => $seriesKey,
            ':printer_id' => $printerId,
            ':label_type' => $labelType,
            ':job_id' => $jobId,
            ':print_status' => $printStatus,
            ':error_message' => $errorMessage,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return ['inserted' => false, 'id' => null];
        }

        return [
            'inserted' => true,
            'id' => (int) $row['id'],
        ];
    }

    /** @return list<array<string,mixed>> */
    public function listRecent(int $limit = 200): array
    {
        $this->ensureSchema();
        $limit = max(1, min($limit, 1000));

        $stmt = $this->database->pdo()->prepare(
            'SELECT id, barcode_hash, barcode_value, name, expiration, brand, home, note, series_key, printer_id, label_type, job_id, print_status, error_message, created_at
             FROM series_printed_barcodes
             ORDER BY created_at DESC
             LIMIT :limit_rows'
        );
        $stmt->bindValue(':limit_rows', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(
            static fn(array $row): array => [
                'id' => (int) $row['id'],
                'hash' => (string) $row['barcode_hash'],
                'barcodeValue' => (string) $row['barcode_value'],
                'name' => $row['name'] !== null ? (string) $row['name'] : '',
                'expiration' => $row['expiration'] !== null ? (string) $row['expiration'] : '',
                'brand' => $row['brand'] !== null ? (string) $row['brand'] : '',
                'home' => $row['home'] !== null ? (string) $row['home'] : '',
                'note' => $row['note'] !== null ? (string) $row['note'] : '',
                'seriesKey' => (string) $row['series_key'],
                'printerId' => (string) $row['printer_id'],
                'labelType' => (string) $row['label_type'],
                'jobId' => $row['job_id'] !== null ? (string) $row['job_id'] : null,
                'status' => (string) $row['print_status'],
                'error' => $row['error_message'] !== null ? (string) $row['error_message'] : null,
                'createdAt' => (string) $row['created_at'],
            ],
            $rows
        );
    }
}

<?php
declare(strict_types=1);

namespace PrinterHub;

use PDO;

final class BatchRepository
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
            "CREATE TABLE IF NOT EXISTS barcode_batches (
                id BIGSERIAL PRIMARY KEY,
                printer_key TEXT NOT NULL,
                symbology TEXT NOT NULL,
                value_count INTEGER NOT NULL,
                values_csv TEXT NOT NULL,
                values_cr TEXT NOT NULL,
                values_json JSONB NOT NULL,
                source_input TEXT NOT NULL,
                print_job_output TEXT,
                sheets_backup_status TEXT NOT NULL DEFAULT 'pending',
                sheets_backup_response TEXT,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                site_geom geometry(Point,4326)
            )"
        );

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_barcode_batches_printer_created ON barcode_batches(printer_key, created_at DESC)');

        $this->schemaReady = true;
    }

    /**
     * @param list<string> $values
     * @return array{id:int,createdAt:string}
     */
    public function create(
        string $printerKey,
        string $symbology,
        array $values,
        string $sourceInput,
        ?float $siteLat,
        ?float $siteLon,
        ?string $printJobOutput
    ): array {
        $this->ensureSchema();

        $pdo = $this->database->pdo();
        $csv = BatchCodec::toCsv($values);
        $cr = BatchCodec::toCr($values);

        $siteWkt = '';
        if ($siteLat !== null && $siteLon !== null) {
            $siteWkt = sprintf('POINT(%F %F)', $siteLon, $siteLat);
        }

        $sql = <<<'SQL'
INSERT INTO barcode_batches (
    printer_key,
    symbology,
    value_count,
    values_csv,
    values_cr,
    values_json,
    source_input,
    print_job_output,
    site_geom
) VALUES (
    :printer_key,
    :symbology,
    :value_count,
    :values_csv,
    :values_cr,
    :values_json,
    :source_input,
    :print_job_output,
    CASE
        WHEN :site_wkt = '' THEN NULL
        ELSE ST_GeomFromText(:site_wkt, 4326)
    END
)
RETURNING id, created_at
SQL;

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':printer_key' => $printerKey,
            ':symbology' => $symbology,
            ':value_count' => count($values),
            ':values_csv' => $csv,
            ':values_cr' => $cr,
            ':values_json' => json_encode($values, JSON_THROW_ON_ERROR),
            ':source_input' => $sourceInput,
            ':print_job_output' => $printJobOutput,
            ':site_wkt' => $siteWkt,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'id' => (int) ($row['id'] ?? 0),
            'createdAt' => (string) ($row['created_at'] ?? ''),
        ];
    }

    public function markSheetsBackup(int $id, string $status, ?string $response): void
    {
        $this->ensureSchema();

        $stmt = $this->database->pdo()->prepare(
            'UPDATE barcode_batches
             SET sheets_backup_status = :status,
                 sheets_backup_response = :response
             WHERE id = :id'
        );

        $stmt->execute([
            ':status' => $status,
            ':response' => $response,
            ':id' => $id,
        ]);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listRecent(?string $printerKey, int $limit = 40): array
    {
        $this->ensureSchema();

        $limit = max(1, min($limit, 100));

        if ($printerKey === null || $printerKey === '') {
            $stmt = $this->database->pdo()->prepare(
                'SELECT id, printer_key, symbology, value_count, values_csv, values_cr, created_at, sheets_backup_status
                 FROM barcode_batches
                 ORDER BY created_at DESC
                 LIMIT :limit'
            );
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
        } else {
            $stmt = $this->database->pdo()->prepare(
                'SELECT id, printer_key, symbology, value_count, values_csv, values_cr, created_at, sheets_backup_status
                 FROM barcode_batches
                 WHERE printer_key = :printer
                 ORDER BY created_at DESC
                 LIMIT :limit'
            );
            $stmt->bindValue(':printer', $printerKey);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(
            static fn(array $row): array => [
                'id' => (int) $row['id'],
                'printer' => (string) $row['printer_key'],
                'symbology' => (string) $row['symbology'],
                'count' => (int) $row['value_count'],
                'csv' => (string) $row['values_csv'],
                'restoredMultiline' => BatchCodec::csvToMultiline((string) $row['values_csv']),
                'createdAt' => (string) $row['created_at'],
                'sheetsBackup' => (string) $row['sheets_backup_status'],
            ],
            $rows
        );
    }
}

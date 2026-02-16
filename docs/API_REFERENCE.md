# API Reference

Base URL: `http://localhost:8088`

## Health
### GET `/api/health`
Returns service health summary (CUPS + DB).

### GET `/api/config`
Legacy + extended configuration payload.

## Unified Print API
### GET `/api/print/config`
Returns printer list, label types, capabilities, and Brother mode.

### POST `/api/print`
Submits a printer job.

Body:
```json
{
  "printerId": "zebra-zp505",
  "labelType": "waco-id",
  "barcodeType": "CODE128",
  "barcodeValue": "051000568235",
  "textLine1": "Spaghettios",
  "copies": 1
}
```

Success response:
```json
{
  "jobId": "a1b2c3d4e5f60708",
  "status": "sent"
}
```

Error response:
```json
{
  "jobId": "a1b2c3d4e5f60708",
  "status": "error",
  "error": "Socket connection failed ..."
}
```

### GET `/api/print/{jobId}`
Returns status payload for polling.

Status lifecycle:
- `queued`
- `sending`
- `sent`
- `error`

### POST `/api/print/diagnostics/brother`
Runs Brother connectivity diagnostics and optional template test print.

Body:
```json
{
  "sendTest": true,
  "labelType": "waco-id",
  "barcodeValue": "051000568235",
  "textLine1": "Spaghettios"
}
```

## Validation Rules
- `printerId`: one of `zebra-zp505`, `brother-ql820`, `hp-envy-5055`
- `labelType`: must exist for selected printer
- `barcodeType`: `CODE128`, `UPCA`, `QR`
- `UPCA`: 11 or 12 digits
- `copies`: `1..250`

## Security and Reliability
- Per-IP rate limit on print endpoints.
- Shell calls use argument arrays (`proc_open` without shell interpolation).
- Job errors are persisted and queryable by job status endpoint.

## Legacy Endpoints (still available)
- `GET /api/printers`
- `POST /api/printers/add`
- `GET /api/queue`
- `GET /api/batches`
- `POST /api/print/brother`
- `POST /api/print/zebra`
- `POST /api/print/hp`
- `POST /api/jobs`

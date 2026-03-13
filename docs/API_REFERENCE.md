# API Reference

Base URL: `http://localhost:8088`

## Health
### GET `/api/health`
Returns service health summary (CUPS + DB).

### GET `/api/config`
Legacy + extended configuration payload.

## Unified Print API
### GET `/api/print/config`
Returns printer list, label types, capabilities, batch metadata, and Brother mode.

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

### POST `/api/batches/save-print-early`
Saves a barcode batch and immediately prints it using the selected printer's batch layout.

Body:
```json
{
  "printerId": "zebra-zp505",
  "labelType": "waco-id",
  "barcodeType": "UPCA",
  "input": "036000291452\n012345678905\n051000012517"
}
```

Behavior:
- accepts newline or comma-separated values
- one barcode type per batch
- Zebra batches are chunked into groups of 12 labels
- HP batches are chunked into groups of 30 labels
- Brother batch mode sends one label per queued value
- `UPCA` accepts 11 or 12 digits per value

Success response:
```json
{
  "saved": true,
  "batchId": 42,
  "count": 3,
  "sentCount": 1,
  "errorCount": 0,
  "barcodeType": "UPCA",
  "rules": {
    "singleSymbologyPerBatch": true,
    "chunkSize": 12,
    "maxValues": 120
  }
}
```

### POST `/api/print/zebra/image`
Prints a PNG as a full 4x6 Zebra label. Accepts either multipart upload, `pngPath`, or `imageBase64`.

Multipart example:
```bash
curl -sS -X POST http://localhost:8088/api/print/zebra/image \
  -F 'file=@/home/fridge/docker/shipping label.PNG' \
  -F 'copies=1'
```

JSON example:
```json
{
  "pngPath": "/tmp/shipping-label.PNG",
  "copies": 1,
  "transport": "auto"
}
```

Fields:
- `file`: multipart PNG upload field name
- `pngPath`: existing PNG path inside the container
- `imageBase64`: base64 or `data:image/png;base64,...`
- `copies`: `1..20`
- `transport`: `auto`, `direct-usb`, or `cups`
- `title`: optional job title
- `threshold`: optional grayscale threshold (`1..254`)

Success response:
```json
{
  "jobId": "a1b2c3d4e5f60708",
  "status": "sent",
  "detail": {
    "transport": "direct-usb",
    "devicePath": "/dev/usb/lp1"
  }
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

### Zebra QR business card payload
Use the normal `POST /api/print` endpoint:

```json
{
  "printerId": "zebra-zp505",
  "labelType": "business-card",
  "barcodeType": "QR",
  "barcodeValue": "https://fridge.local/services",
  "textLine1": "Charlie",
  "copies": 1
}
```

Notes:
- Zebra QR labels are rasterized to image-backed ZPL for reliable output on the ZP-505.
- `business-card` requires `textLine1` for the printed name.
- `barcodeValue` must be a valid URL for `business-card`; the UI auto-normalizes missing schemes.

## Validation Rules
- `printerId`: one of `zebra-zp505`, `brother-ql820`, `hp-envy-5055`
- `labelType`: must exist for selected printer
- `barcodeType`: `CODE128`, `UPCA`, `QR`
- `UPCA`: 11 or 12 digits
- Zebra `business-card`: requires `barcodeType=QR`, non-empty `textLine1`, and a valid URL in `barcodeValue`
- `copies`: `1..250`
- batch submission size: `1..120` values by default, subject to per-printer config

## Security and Reliability
- Per-IP rate limit on print endpoints.
- Shell calls use argument arrays (`proc_open` without shell interpolation).
- Job errors are persisted and queryable by job status endpoint.

## Legacy Endpoints (still available)
- `GET /api/printers`
- `POST /api/printers/add`
- `GET /api/queue`
- `GET /api/batches`
- `POST /api/batches/save-print-early`
- `POST /api/print/brother`
- `POST /api/print/zebra`
- `POST /api/print/hp`
- `POST /api/jobs`

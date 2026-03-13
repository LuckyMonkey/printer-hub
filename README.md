# Printer Hub

Printer Hub is an all-in-one local print platform for three printer workflows:
- Zebra ZP-505: raw ZPL
- Brother QL-820NWB: P-touch Template mode (template select + object injection)
- HP Envy 5055: normal CUPS text print (minimal path)

The container runs:
- `nginx` (React UI + API routing)
- `php-fpm` (API backend)
- `cups` (queues + dispatch)
- `postgresql + postgis` (existing persistence + print job tracking)

## Quick Start
```bash
cd /home/fridge/docker/printer-hub
cp .env.example .env
docker-compose up -d --build
```

- UI: `http://localhost:8088/ui/printers`
- CUPS Admin: `http://localhost:8631/`
- Health: `http://localhost:8088/api/health`

## UI
- Printer list: `/ui/printers`
- Zebra form: `/ui/printers/zebra-zp505`
- Brother form: `/ui/printers/brother-ql820`
- HP form: `/ui/printers/hp-envy-5055`

Each printer page has:
- label type selector
- barcode type selector (`CODE128`, `UPCA`, `QR`)
- `barcodeValue`, optional `textLine1`, `copies`
- `Print` button + `Test` button
- status polling (`queued`, `sending`, `sent`/printed, `error`)
- guided batch printing controls with live counts, duplicate warnings, and chunk/page estimates
- Zebra batch mode defaults to `UPCA` and normalizes 11-digit UPC-A values before submit
- Zebra QR labels are rasterized before dispatch for more reliable ZP-505 output
- Zebra `business-card` labels expect `textLine1 = name` and `barcodeValue = link URL`

## API
- `GET /api/print/config`
- `POST /api/print`
- `POST /api/batches/save-print-early`
- `POST /api/print/zebra/image`
- `GET /api/print/{jobId}`
- `POST /api/print/diagnostics/brother`

Example:
```bash
curl -sS -X POST http://localhost:8088/api/print \
  -H 'Content-Type: application/json' \
  -d '{
    "printerId": "zebra-zp505",
    "labelType": "waco-id",
    "barcodeType": "CODE128",
    "barcodeValue": "051000568235",
    "textLine1": "Spaghettios",
    "copies": 1
  }'
```

PNG label example:
```bash
curl -sS -X POST http://localhost:8088/api/print/zebra/image \
  -F 'file=@/home/fridge/docker/shipping label.PNG' \
  -F 'copies=1'
```

Batch Zebra UPC example:
```bash
curl -sS -X POST http://localhost:8088/api/batches/save-print-early \
  -H 'Content-Type: application/json' \
  -d '{
    "printerId": "zebra-zp505",
    "labelType": "waco-id",
    "barcodeType": "UPCA",
    "input": "036000291452\n012345678905\n051000012517"
  }'
```

Zebra QR business card example:
```bash
curl -sS -X POST http://localhost:8088/api/print \
  -H 'Content-Type: application/json' \
  -d '{
    "printerId": "zebra-zp505",
    "labelType": "business-card",
    "barcodeType": "QR",
    "barcodeValue": "https://fridge.local/services",
    "textLine1": "Charlie",
    "copies": 1
  }'
```

## Printer Transport Setup

### 1) Set printer hosts/queues
Edit `.env` (see `.env.example`):
- Zebra: `ZEBRA_TRANSPORT`, `ZEBRA_HOST`, `ZEBRA_PORT`, `PRINTER_ZEBRA_QUEUE`, `ZEBRA_IMAGE_TRANSPORT`, `ZEBRA_USB_DEVICE`, `ZEBRA_IMAGE_THRESHOLD`
- Brother: `BROTHER_MODE`, `BROTHER_TRANSPORT`, `BROTHER_HOST`, `BROTHER_PORT`, `PRINTER_BROTHER_QUEUE`
- HP: `PRINTER_HP_QUEUE`

### 2) Create raw CUPS queues (if using CUPS transport)
Use `raw` model so no filtering occurs for Zebra/Brother protocol bytes:
```bash
# Zebra raw queue (socket/AppSocket)
lpadmin -p zebra_zp505 -E -v socket://192.168.1.120:9100 -m raw
cupsenable zebra_zp505
accept zebra_zp505

# Brother raw queue (socket/AppSocket)
lpadmin -p brother_ql820nwb -E -v socket://192.168.1.121:9100 -m raw
cupsenable brother_ql820nwb
accept brother_ql820nwb

# HP normal queue (drivered/IPP)
# Example URI varies per network/discovery:
lpadmin -p hp_envy_5055 -E -v ipp://192.168.1.122/ipp/print -m everywhere
cupsenable hp_envy_5055
accept hp_envy_5055
```

### 3) Brother templates and label mapping
The Brother template map lives in `app/config/printers.php`:
- `labelType -> templateId`
- object mapping (`barcodeObjectIndex`, `textObjectIndex`)

Default map:
- `waco-id -> templateId 1`
- `status-tag -> templateId 2`
- object `1 = barcodeValue`, object `2 = textLine1`

Load your templates onto the QL-820NWB (P-touch Template / Template Appliance mode), then keep `templateId` and object indices aligned with `app/config/printers.php`.

### 4) Brother diagnostic test
```bash
curl -sS -X POST http://localhost:8088/api/print/diagnostics/brother \
  -H 'Content-Type: application/json' \
  -d '{"sendTest": true, "labelType": "waco-id"}'
```

## Notes
- Raw socket/CUPS success means data was sent to the printer transport; UI treats `sent` as printed success.
- Zebra batch submissions are chunked into groups of 12 labels per print job.
- Zebra PNG image printing can bypass CUPS and write directly to `ZEBRA_USB_DEVICE` when `ZEBRA_IMAGE_TRANSPORT=direct-usb`.
- Zebra QR label printing uses a PNG raster-to-ZPL path instead of native `^BQN` QR commands.
- Rate limiting is enabled for print endpoints (per-IP, per-minute).
- Structured job logs are written to `PRINT_JOB_LOG_PATH`.

## Documentation Index
- `docs/ARCHITECTURE.md`
- `docs/API_REFERENCE.md`
- `docs/PRINTER_SETUP.md`
- `docs/DEVELOPMENT.md`
- `docs/TROUBLESHOOTING.md`

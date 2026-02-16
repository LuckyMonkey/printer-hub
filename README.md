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

## API
- `GET /api/print/config`
- `POST /api/print`
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

## Printer Transport Setup

### 1) Set printer hosts/queues
Edit `.env` (see `.env.example`):
- Zebra: `ZEBRA_TRANSPORT`, `ZEBRA_HOST`, `ZEBRA_PORT`, `PRINTER_ZEBRA_QUEUE`
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
- Rate limiting is enabled for print endpoints (per-IP, per-minute).
- Structured job logs are written to `PRINT_JOB_LOG_PATH`.

## Documentation Index
- `docs/ARCHITECTURE.md`
- `docs/API_REFERENCE.md`
- `docs/PRINTER_SETUP.md`
- `docs/DEVELOPMENT.md`
- `docs/TROUBLESHOOTING.md`

# Printer Hub

All-in-one local print platform for three printer workflows with one UI:
- Zebra ZP505 (USB): 12 barcodes on a 4x6 ZPL label (raster Z64 or native ZPL)
- Brother QL-820NWB (network): single barcode on 2.4x1.1 label
- HP Envy 5055 (network): 30 barcodes on a 3x10 sheet

The container runs:
- `nginx` (serves React UI + routes API)
- `php-fpm` (print workflow API)
- `cups` (print queue management + dispatch)
- `postgresql + postgis` (batch persistence)

## Features
- OS9-style centered UI with 3 buttons and per-printer pages.
- Strict per-printer count validation (1 / 12 / 30).
- Barcode symbologies: `code128`, `qr`, `upc`.
- Zebra raster path: GD-generated monochrome image packed to `^GFA ... :Z64:`.
- CR/newline/comma input normalization to CSV + restore to multiline.
- Stored batch history in PostGIS-backed Postgres.
- Optional Google Sheets backup via Apps Script webhook.

## Quick Start
```bash
cd /home/fridge/docker/printer-hub
docker-compose up -d --build
```

- UI: `http://localhost:8088/ui/`
- CUPS Admin: `http://localhost:8631/`
- API Health: `http://localhost:8088/api/health`

## Routes
- Home: `/ui/`
- Brother page: `/ui/printers/brother`
- Zebra page: `/ui/printers/zebra`
- HP page: `/ui/printers/hp`

## Zebra Render Modes
`POST /api/print/zebra` accepts optional `zebraMode`:
- `auto` (default): `code128` and `upc` use raster Z64, `qr` uses native ZPL.
- `z64`: force raster mode (`code128` and `upc` only).
- `native`: force native barcode ZPL commands.

## Environment
Compose reads from `.env`.

Create from template:
```bash
cp .env.example .env
```

Required for Google Sheets backup:
- `GAPPS_WEBHOOK_URL=https://script.google.com/macros/s/.../exec`

## Documentation Index
- Architecture: `docs/ARCHITECTURE.md`
- API reference: `docs/API_REFERENCE.md`
- Deployment and operations: `docs/DEPLOYMENT_AND_OPS.md`
- Printer queue setup: `docs/PRINTER_SETUP.md`
- Google Apps Script backup: `docs/GOOGLE_SHEETS_BACKUP.md`
- Development guide: `docs/DEVELOPMENT.md`
- Troubleshooting: `docs/TROUBLESHOOTING.md`
- Contributing: `CONTRIBUTING.md`
- Security policy: `SECURITY.md`

## Repo Setup For First Push
```bash
git init
git branch -M main
git add .
git commit -m "chore: initial printer-hub platform"
# set remote and push
# git remote add origin <your-repo-url>
# git push -u origin main
```

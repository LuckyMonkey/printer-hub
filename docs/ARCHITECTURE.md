# Architecture

## Overview
Printer Hub is a single-container service orchestrated by `supervisord`.

### Runtime processes
- `postgres`: stores batches and metadata
- `postgres-init`: boot-time role/db/extension setup
- `cupsd`: queue and print transport layer
- `php-fpm`: application backend
- `nginx`: static UI + API reverse routing

## Request Flow
1. User opens React UI (`/ui/*`).
2. UI sends request to `/api/print/<printer>`.
3. PHP validates count/symbology/input.
4. PHP renders output:
   - Zebra: ZPL 12-up (native commands or GD raster -> Z64 `^GFA`)
   - Brother/HP: PDF via Python `reportlab`
5. PHP submits print job through CUPS (`lp`).
6. PHP persists batch in Postgres/PostGIS.
7. PHP optionally POSTs JSON payload to Google Apps Script.

## Storage Model
Table: `barcode_batches`
- `printer_key`, `symbology`, `value_count`
- `values_csv`, `values_cr`, `values_json`
- `source_input`
- `print_job_output`
- `sheets_backup_status`, `sheets_backup_response`
- `created_at`
- `site_geom geometry(Point,4326)`

## Code Map
- API router: `app/src/ApiController.php`
- Workflow engine: `app/src/PrintWorkflowService.php`
- Zebra raster encoder: `app/src/ZplRasterService.php`
- CUPS service layer: `app/src/PrinterService.php`, `app/src/JobService.php`
- DB layer: `app/src/Database.php`, `app/src/BatchRepository.php`
- Input normalization: `app/src/BatchCodec.php`
- Sheets integration: `app/src/SheetsBackupService.php`
- PDF renderer: `app/scripts/render_labels.py`
- UI: `ui/src/App.jsx`, `ui/src/styles.css`

## Network/Ports
- `8088 -> 80`: UI + API
- `8631 -> 631`: CUPS Admin/API
- Internal DB listens on `127.0.0.1:5432`

## Why Single Container
This project intentionally packs all services into one image to simplify local/home-lab deployment where printer USB pass-through and local management are primary goals.

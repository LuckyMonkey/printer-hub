# Architecture

## Overview
Printer Hub runs as a single container orchestrated by `supervisord`.

### Runtime Processes
- `postgres`: persistent storage
- `cupsd`: queue + print dispatch
- `php-fpm`: API backend
- `nginx`: UI + API routing

## Request Flow (Unified Print)
1. React UI calls `POST /api/print`.
2. PHP validates payload and printer capability constraints.
3. Job is persisted with `queued -> sending` status.
4. Printer-specific pipeline runs:
   - Zebra: ZPL generated and sent raw (socket or CUPS raw queue)
   - Brother: P-touch Template command stream generated and sent raw
   - HP: simple text document printed through CUPS
5. Job status updates to `sent` or `error`.
6. UI polls `GET /api/print/{jobId}`.

## Backend Components
- API router: `app/src/ApiController.php`
- Printer config loader: `app/src/PrinterRegistry.php`
- Unified print service: `app/src/MultiPrinterPrintService.php`
- Zebra ZPL builder: `app/src/ZebraLabelService.php`
- Brother template protocol builder: `app/src/BrotherTemplateClient.php`
- CUPS transport: `app/src/CupsTransport.php`
- Socket transport: `app/src/RawSocketTransport.php`
- Job store + errors: `app/src/PrintJobRepository.php`
- Structured logging: `app/src/JobLogger.php`
- Rate limiting: `app/src/RateLimiter.php`

## UI Components
- App shell + routing: `ui/src/App.jsx`
- Tailwind entry: `ui/src/index.css`
- Tailwind config: `ui/tailwind.config.js`

## Data Model
Table: `print_jobs`
- `job_id`, `printer_id`, `label_type`
- `barcode_type`, `barcode_value`, `text_line1`, `copies`
- `status`, `error_message`, `payload_summary`
- `created_at`, `updated_at`

Table: `print_job_errors`
- `printer_id`, `job_id`, `error_message`, `created_at`

## Ports
- `8088 -> 80`: UI + API
- `8631 -> 631`: CUPS

# API Reference

Base URL: `http://localhost:8088`

## Health and Config
### GET `/api/health`
Returns backend health including CUPS/DB checks.

### GET `/api/config`
Returns queue mapping, required barcode counts, and supported symbologies.

## Printer Management
### GET `/api/printers`
Returns installed CUPS printers and default printer.

### POST `/api/printers/add`
Creates or updates a CUPS queue.

Body:
```json
{
  "name": "zebra_zp505",
  "uri": "usb://Zebra%20Technologies/...",
  "model": "raw",
  "setDefault": true
}
```

## Queue and Batches
### GET `/api/queue`
Returns pending CUPS jobs.

### GET `/api/batches?printer=<brother|zebra|hp>&limit=20`
Returns recent persisted batches.

## Print Endpoints
### POST `/api/print/brother`
- Requires exactly 1 value
- Uses single 2.4x1.1 PDF flow

### POST `/api/print/zebra`
- Requires exactly 12 values
- Uses 4x6 ZPL 12-up flow (`lp -o raw`)
- Optional `zebraMode`: `auto` (default), `z64`, `native`
- `auto` behavior: `code128/upc` render as raster `^GFA ... :Z64:`, `qr` falls back to native ZPL
- Response includes `zebraRenderMode` with the effective mode used.

### POST `/api/print/hp`
- Requires exactly 30 values
- Uses 3x10 sheet PDF flow

Common body:
```json
{
  "symbology": "code128",
  "zebraMode": "auto",
  "title": "job-name",
  "copies": 1,
  "input": "A1\rA2\nA3,A4"
}
```

## Validation Rules
- Symbology: `code128`, `qr`, `upc`
- UPC values: 11 or 12 digits
- Copies: 1..250
- Input parser separators: CR, newline, comma

## Legacy Compatibility
### POST `/api/jobs`
Original generic endpoint remains for backward compatibility.

# Google Sheets Backup

## Purpose
Every submitted print batch can be copied to Google Sheets through a Google Apps Script webhook.

## Script Source
Use: `gapps/Code.gs`

## Deploy Steps
1. Open Google Sheet.
2. `Extensions -> Apps Script`.
3. Paste `Code.gs`.
4. `Deploy -> New deployment -> Web app`.
5. Set:
   - Execute as: `Me`
   - Access: `Anyone`
6. Copy `/exec` URL.

## Configure Service
1. Edit `.env`:
   ```env
   GAPPS_WEBHOOK_URL=https://script.google.com/macros/s/.../exec
   ```
2. Restart stack:
   ```bash
   docker-compose up -d
   ```

## Payload Fields
- `batchId`
- `printer`
- `printerQueue`
- `symbology`
- `count`
- `csv`
- `carriageReturn`
- `values[]`
- `createdAt`

## DB Status Tracking
Each batch stores backup result in:
- `sheets_backup_status`
- `sheets_backup_response`

## Failure Behavior
If webhook is unavailable:
- Print and DB save still complete.
- Backup status is marked as error.

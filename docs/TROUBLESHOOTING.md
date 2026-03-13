# Troubleshooting

## UI not reachable on :8088
- Check container state:
  ```bash
  docker-compose ps
  ```
- Check logs:
  ```bash
  docker-compose logs --tail=200
  ```
- Ensure port `8088` is not already in use.

## CUPS not running
Symptoms:
- `/api/health` returns CUPS error
- print jobs fail immediately

Actions:
1. Review CUPS config syntax in `config/cupsd.conf`
2. Check CUPS logs inside container:
   ```bash
   docker exec printer-hub sh -lc 'tail -n 200 /var/log/cups/error_log'
   ```

## Database errors
Symptoms:
- `/api/health` reports DB error
- batch save fails

Actions:
1. Check `postgres` process in logs.
2. Confirm DB env values in compose.
3. Verify PostGIS extension exists:
   ```bash
   docker exec printer-hub sh -lc "PGPASSWORD=$DB_PASSWORD psql -h 127.0.0.1 -U $DB_USER -d $DB_NAME -c '\\dx postgis'"
   ```

## No printers listed
`/api/printers` returns empty list when no queues are configured.

Add queues via:
- CUPS admin UI `:8631`
- `POST /api/printers/add`

## Zebra prints garbage or blank labels
- Ensure queue model is `raw`.
- Ensure Zebra queue name matches `PRINTER_ZEBRA_QUEUE`.
- Verify USB mapping `/dev/bus/usb` works on host.

## Zebra QR labels do not print
- QR labels now use the raster QR path, so both `gd` and `qrencode` must exist in the container image.
- Rebuild the container after QR-related code changes:
  ```bash
  docker-compose up -d --build
  ```
- Run the QR service test inside the container:
  ```bash
  docker exec printer-hub php /var/www/app/tests/ZebraQrLabelServiceTest.php
  ```
- If a `business-card` label fails, confirm `barcodeValue` is a real URL and `textLine1` is not blank.

## Zebra PNG job hangs in CUPS or nothing prints
- If `lpstat -p zebra_zp505 -l` shows `Waiting for printer to become available`, the CUPS USB backend may be wedged.
- Prefer the built-in PNG endpoint with direct USB enabled:
  ```bash
  curl -sS -X POST http://localhost:8088/api/print/zebra/image \
    -F 'file=@/path/to/label.png'
  ```
- Confirm `ZEBRA_IMAGE_TRANSPORT=direct-usb` and `ZEBRA_USB_DEVICE=/dev/usb/lp1`.
- Check the device exists in the container:
  ```bash
  docker exec printer-hub sh -lc 'ls -l /dev/usb/lp*'
  ```

## Brother or HP print misalignment
- Validate page/stock selection on physical printer.
- Ensure correct URI and model.
- Test with 1 known barcode first, then full count.

## Google Sheets backup not writing rows
- Verify `GAPPS_WEBHOOK_URL` in `.env`.
- Confirm Apps Script deployment access is `Anyone`.
- Check batch backup status fields through API `/api/batches`.

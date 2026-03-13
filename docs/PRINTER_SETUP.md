# Printer Setup

## Queue Names (default)
- Brother: `brother_ql820nwb`
- Zebra: `zebra_zp505`
- HP: `hp_envy_5055`

These can be overridden via environment variables in `docker-compose.yml`.

## Add Queues via API
Endpoint: `POST /api/printers/add`

## Zebra ZP505 (USB)
- Typical URI pattern: `usb://Zebra%20Technologies/...`
- Model: `raw`
- Workflow uses ZPL pass-through (`lp -o raw`)
- Batch workflow prints up to 12 labels per Zebra job
- UPC-A batches accept 11 or 12 digits per value
- QR labels are rasterized to ZPL image data before dispatch for more consistent output
- `business-card` QR labels use `textLine1` as the name and `barcodeValue` as the link URL

## Brother QL-820NWB (Network)
- Typical URI pattern: `socket://<brother-ip>:9100`
- Use `raw` or tested model depending your environment
- Workflow expects 1 barcode per submission

## HP Envy 5055 (Network)
- Typical URI pattern: `ipp://<hp-ip>/ipp/print`
- Model: `everywhere` preferred for IPP devices
- Workflow expects 30 barcodes per submission

## Verify CUPS Queues
- CUPS web admin: `http://localhost:8631/`
- API: `GET /api/printers`
- Batch print endpoint: `POST /api/batches/save-print-early`

## Common Mistakes
- Wrong queue name in env vs CUPS actual queue name
- Using non-raw model for Zebra ZPL queue
- Network printer reachable from host but not from container network path

# Changelog

## Unreleased
- Added Zebra PNG image printing via `POST /api/print/zebra/image`.
- Added a built-in PNG-to-ZPL renderer with the palette-to-truecolor raster fix that avoids solid-black labels.
- Added direct USB Zebra image printing via `ZEBRA_IMAGE_TRANSPORT` and `ZEBRA_USB_DEVICE`.
- Added guided batch printing with per-printer chunk rules, including 12-up Zebra UPC sheets.
- Added Zebra QR raster label rendering and the Zebra `business-card` label type for name + link cards.
- Added UI validation and URL normalization for Zebra QR business-card printing.

## 0.1.0 - Initial
- Added all-in-one container stack: nginx + php-fpm + CUPS + PostgreSQL/PostGIS.
- Added OS9-style 3-button React UI with dedicated printer pages.
- Added strict printer workflows:
  - Brother: 1 barcode
  - Zebra: 12 barcode 4x6 ZPL
  - HP: 30 barcode sheet PDF
- Added symbology support: Code128, QR, UPC.
- Added batch persistence with CSV/CR normalization and restore support.
- Added optional Google Sheets backup webhook via Apps Script.
- Added full documentation set for architecture, API, setup, operations, and troubleshooting.

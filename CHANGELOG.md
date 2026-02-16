# Changelog

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

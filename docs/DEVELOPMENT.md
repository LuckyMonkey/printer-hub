# Development Guide

## Project Layout
- `app/`: PHP backend + protocol generators + tests
- `ui/`: React + Tailwind frontend
- `config/`: nginx/cups/supervisor/runtime config
- `docs/`: project documentation

## UI Development
```bash
cd ui
npm install
npm run dev
```

## Backend Notes
- Entry point: `app/public/index.php`
- Unified print API: `app/src/MultiPrinterPrintService.php`
- Printer definitions: `app/config/printers.php`

## Local Checks
- Brother template command snapshot test:
  ```bash
  php app/tests/BrotherTemplateClientSnapshotTest.php
  ```
- Zebra label batch rules:
  ```bash
  php app/tests/ZebraLabelServiceTest.php
  php app/tests/ZebraQrLabelServiceTest.php
  php app/tests/PrinterRegistryTest.php
  ```
- UI production build:
  ```bash
  cd ui
  npm run build
  ```
- Full container rebuild:
  ```bash
  docker-compose up -d --build
  ```

## Documentation Discipline
Any behavior change must update `README.md` and relevant files in `docs/`.

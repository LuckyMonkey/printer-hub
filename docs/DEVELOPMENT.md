# Development Guide

## Project Layout
- `app/`: PHP backend and print rendering script
- `ui/`: React/Vite frontend
- `config/`: nginx/cups/supervisor/entrypoint configs
- `gapps/`: Google Apps Script source
- `docs/`: operational and technical documentation

## UI Development
```bash
cd ui
npm install
npm run dev
```

## Backend Notes
- Entry point: `app/public/index.php`
- API router: `app/src/ApiController.php`
- CUPS command execution via `CommandRunner`

## Print Render Paths
- Zebra: generated `.zpl` in `/tmp/printer-hub`
- Brother/HP: generated `.pdf` via `render_labels.py`

## Local Code Checks
- Python syntax:
  ```bash
  python3 -m py_compile app/scripts/render_labels.py
  ```
- Container integration:
  ```bash
  docker-compose up -d --build
  ```

## Documentation Discipline
Any behavior change must update relevant file in `docs/` and `README.md`.

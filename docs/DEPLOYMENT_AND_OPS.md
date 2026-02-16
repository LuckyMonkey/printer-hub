# Deployment and Operations

## Prerequisites
- Docker + docker-compose
- USB pass-through support for Zebra (host dependent)

## Boot
```bash
cd /home/fridge/docker/printer-hub
docker-compose up -d --build
```

## Stop
```bash
docker-compose down
```

## Rebuild after changes
```bash
docker-compose build
docker-compose down
docker-compose up -d
```

## Environment Variables
From `docker-compose.yml` and `.env`:
- `TZ`
- `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`
- `PRINTER_BROTHER_QUEUE`, `PRINTER_ZEBRA_QUEUE`, `PRINTER_HP_QUEUE`
- `GAPPS_WEBHOOK_URL`
- `SITE_LAT`, `SITE_LON`

## Runtime Verification
```bash
curl -s http://localhost:8088/api/health
curl -s http://localhost:8088/api/config
curl -s http://localhost:8088/api/printers
```

## Data Persistence
Docker volumes:
- `cups_etc`
- `cups_spool`
- `cups_logs`
- `printer_data`
- `pg_data`

## Logs
```bash
docker-compose logs --tail=200
```

## Backup Considerations
- DB data: volume `pg_data`
- CUPS definitions and spool: `cups_*` volumes
- Google Sheets backup is additive, not a DB replacement

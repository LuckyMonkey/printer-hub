# Security Policy

## Supported Versions
This project currently supports the latest `main` branch.

## Reporting
If you discover a security issue:
1. Do not post public exploit details immediately.
2. Share a private report with:
   - Affected component(s)
   - Reproduction steps
   - Impact estimate
   - Suggested mitigation (if available)

## Security Notes
- CUPS admin is exposed on `8631`; restrict network access as needed.
- The container runs privileged for USB printer access. Use only on trusted local hosts.
- Keep `GAPPS_WEBHOOK_URL` in `.env`, never hardcoded in tracked files.
- Rotate credentials if leaked (`DB_PASSWORD`, webhook URLs).

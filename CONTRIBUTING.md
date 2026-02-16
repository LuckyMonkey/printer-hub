# Contributing

## Branching
- Use short-lived branches from `main`.
- Prefix examples: `feat/...`, `fix/...`, `docs/...`.

## Commit Style
- Keep commits focused and atomic.
- Suggested pattern: `type: summary`
  - `feat: ...`
  - `fix: ...`
  - `docs: ...`
  - `chore: ...`

## Local Validation
Before opening a PR:
1. Build and start stack:
   ```bash
   docker-compose up -d --build
   ```
2. Verify health:
   ```bash
   curl -s http://localhost:8088/api/health
   ```
3. Validate UI routes:
   - `/ui/`
   - `/ui/printers/brother`
   - `/ui/printers/zebra`
   - `/ui/printers/hp`
4. Verify no regressions in queue/print APIs.

## Pull Requests
- Include scope, rationale, and test notes.
- Include screenshots for UI changes.
- Document env/config changes in `README.md` and `docs/*`.

## Documentation Standard
- Any endpoint or workflow change must update:
  - `docs/API_REFERENCE.md`
  - `docs/DEPLOYMENT_AND_OPS.md`
  - `docs/TROUBLESHOOTING.md` when relevant

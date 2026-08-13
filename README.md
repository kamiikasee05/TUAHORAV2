# TUAHORAV2

SaaS de turnos online 24/7 con recordatorios por WhatsApp para pequeños negocios locales.
v2 minimalista: landing del profesional + reserva online + dashboard acotado.

## Estructura

- `scheduler/` — motor de turnos (Node + Express + SQLite `node:sqlite`). Sirve también la SPA web y `config.json`.
- `php/` — capa PHP acotada: dashboard del profesional (`php/admin/`) + proxys (`php/api/`).
- `web/` — SPA estática (Lado A: landing, Lado B: reserva). La sirve el scheduler.

## Levantar en local

1. Configurar el scheduler:

   ```
   cd scheduler
   copy .env.example .env   # y completar API_KEY, OPENWA_* etc.
   npm install
   npm run dev
   ```

   → Scheduler en http://127.0.0.1:3000 (SPA en `/`, admin en `/admin`, config en `/config.json`).

2. Configurar el admin PHP (requiere PHP CLI con `curl` + `sqlite3`):

   ```
   cd php
   php -S 127.0.0.1:8080
   ```

   → Admin en http://127.0.0.1:8080/admin/. Las vars se toman del entorno
   (`SCHEDULER_URL`, `SCHEDULER_API_KEY`, `ADMIN_PASSWORD_HASH`, `PROVIDER_ID`, `OPENWA_*`).

3. OpenWA (WhatsApp) corriendo aparte en `127.0.0.1:2785`.

## Tests

```
cd scheduler
npm test
```

## Env vars principales

Scheduler: `PORT`, `API_KEY`, `CORS_ORIGIN`, `PROVIDER_ID` (default 5), `DATA_DIR`,
`OPENWA_BASE_URL` (o `OPENWA_HOST`+`OPENWA_PORT`), `OPENWA_API_KEY`, `OPENWA_SESSION_ID`.

PHP: `SCHEDULER_URL`, `SCHEDULER_API_KEY`, `ADMIN_PASSWORD_HASH`, `PROVIDER_ID`,
`OPENWA_BASE_URL`, `OPENWA_API_KEY`, `OPENWA_SESSION_ID`.

Ver `.env.example`.

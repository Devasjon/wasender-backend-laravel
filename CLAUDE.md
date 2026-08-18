# Paybill WhatsApp Bridge (Laravel)

A small Laravel API with two jobs for the Paybill frontend (a
browser-only React app — see the sibling `paybill-kanban-dashboard`
project):

1. Keep the WasenderAPI Session API Key secret on a server, so the frontend
   can trigger WhatsApp notifications without ever holding that key itself.
2. Store Paybill's full app state (bills, debts, settings) so it's the same
   on every device instead of living only in one browser's memory.

Meant to be deployed via Laravel Forge, but runs anywhere Laravel runs.

This is a fresh `laravel/laravel` skeleton (framework `^13.17` at time of
writing) with a handful of files changed/added from stock. Everything else
is the standard Laravel scaffold — normal artisan commands, config files,
migrations, etc. all work as expected. **This app now needs a database**
(SQLite by default, per `.env.example`'s `DB_CONNECTION=sqlite`) — it's no
longer just a stateless forwarder.

## What's actually custom here

WhatsApp bridge:
- `app/Http/Controllers/Api/SendWhatsAppController.php` — the whole feature.
  `POST` with `{ to, text }` in the body → validates → forwards to
  `https://www.wasenderapi.com/api/send-message` with
  `Authorization: Bearer {WASENDER_API_KEY}` → returns `{ ok: true, wasender: {...} }`
  or `{ error, detail }`. Optional `?key=...` query-param check against
  `WASENDER_SHARED_SECRET` (see "Security" below).
- `tests/Feature/SendWhatsAppTest.php` — feature tests using `Http::fake()`
  to mock the outbound call to WasenderAPI. Covers: happy path,
  missing-field validation (422), unconfigured key (500), wrong shared
  secret (401).

Cloud sync (multi-device persistence):
- `database/migrations/2026_01_01_000000_create_app_states_table.php` — one
  table, `app_states`, storing the entire `AppData` JSON blob (same shape
  as the frontend's Export/Import feature) keyed by `owner` (always
  `"default"` — this is a single-user app, not multi-tenant).
- `app/Models/AppState.php` — trivial Eloquent model, `data` cast to array.
- `app/Http/Controllers/Api/AppStateController.php` — `GET /api/state`
  returns the stored blob (or `{ data: null }` if nothing's synced yet);
  `PUT /api/state` with `{ data: {...} }` overwrites it
  (`updateOrCreate`, so there's always exactly one row).
- `app/Http/Middleware/EnsurePaybillAccessToken.php` — bearer-token check
  registered as the `paybill.token` alias, applied to both `/api/state`
  routes. Deliberately **fails closed**: if `APP_ACCESS_TOKEN` isn't set in
  `.env`, every request gets a 500, not an open endpoint.
- `tests/Feature/AppStateTest.php` — covers auth rejection (no token, wrong
  token, unconfigured token), the "nothing synced yet" empty state, save +
  read-back, and that repeated saves overwrite rather than accumulate rows.

Shared plumbing (touched by both features):
- `routes/api.php` — registers `POST /send-whatsapp` and the two `/state`
  routes (behind `paybill.token`). A fresh Laravel skeleton doesn't ship
  `routes/api.php` at all by default; it was created and wired into
  `bootstrap/app.php` here.
- `config/services.php` — `wasender` block (WhatsApp) and `paybill` block
  (`access_token` for cloud sync). Everything else in this file is stock.
- `bootstrap/app.php` — added the `api:` route file registration and the
  `paybill.token` middleware alias. Everything else is stock.

## Setup

```
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
```

Edit `.env`:
```
WASENDER_API_KEY=your_session_api_key_here
# optional:
WASENDER_SHARED_SECRET=pick-a-long-random-string

# required for cloud sync — generate with:
# php artisan tinker --execute="echo Str::random(40);"
APP_ACCESS_TOKEN=paste-a-generated-token-here
```

`WASENDER_API_KEY` is the per-session **Session API Key** from the
WasenderAPI dashboard (the one generated when you connect a WhatsApp session
via QR code) — not the account-level Personal Access Token.

Run locally:
```
php artisan serve
```

Test:
```
php artisan test

curl -X POST http://127.0.0.1:8000/api/send-whatsapp \
  -H "Content-Type: application/json" \
  -d '{"to": "+60123456789", "text": "Ujian"}'

curl -X PUT http://127.0.0.1:8000/api/state \
  -H "Authorization: Bearer paste-a-generated-token-here" \
  -H "Content-Type: application/json" \
  -d '{"data": {"bills": [], "debts": []}}'

curl http://127.0.0.1:8000/api/state \
  -H "Authorization: Bearer paste-a-generated-token-here"
```

## Deploying

See `README.md` for the full Laravel Forge walkthrough (create site, connect
Git repo, set env vars, deploy, SSL, connect the resulting URL to Paybill's
Settings page). Any other Laravel host works too — there's nothing
Forge-specific in the code itself, Forge is just the deploy target this was
built for.

## Security

Without `WASENDER_SHARED_SECRET` set, anyone who discovers the WhatsApp
endpoint's URL can POST to it and send messages through your connected
session — there's no auth on the Paybill frontend to piggyback on. Set
`WASENDER_SHARED_SECRET` in `.env` and put the same value as `?key=...` on
the URL you paste into Paybill's Settings → Backend endpoint URL.

`/api/state` holds real bill/debt data, so it's protected by a required
bearer token (`APP_ACCESS_TOKEN`) rather than an optional one — the
middleware refuses to serve requests at all if that env var is unset,
rather than silently running open. Put the same token into Paybill's
Settings → Cloud Sync → Access Token field.

The `api/*` route group is also throttled by Laravel's default rate limiter
(60 req/min) as a second layer on both endpoints.

## Related project

`paybill-kanban-dashboard/` (delivered separately, own git history) is the
React frontend that calls these endpoints from `src/lib/whatsapp.ts`
(WhatsApp) and `src/lib/cloudSync.ts` (state sync). If you change either
controller's request/response shape, update the matching frontend file.

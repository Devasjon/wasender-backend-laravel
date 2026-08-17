# Paybill WhatsApp Bridge (Laravel)

A one-endpoint Laravel API that keeps the WasenderAPI Session API Key secret
on a server, so the Paybill frontend (a static/browser-only React app — see
the sibling `paybill-kanban-dashboard` project) can trigger WhatsApp
notifications without ever holding that key itself. Meant to be deployed via
Laravel Forge, but runs anywhere Laravel runs.

This is a fresh `laravel/laravel` skeleton (framework `^13.17` at time of
writing) with exactly four files changed from stock, plus one added test
file. Everything else is the standard Laravel scaffold — normal artisan
commands, config files, etc. all work as expected.

## What's actually custom here

- `app/Http/Controllers/Api/SendWhatsAppController.php` — the whole feature.
  `POST` with `{ to, text }` in the body → validates → forwards to
  `https://www.wasenderapi.com/api/send-message` with
  `Authorization: Bearer {WASENDER_API_KEY}` → returns `{ ok: true, wasender: {...} }`
  or `{ error, detail }`. Optional `?key=...` query-param check against
  `WASENDER_SHARED_SECRET` (see "Security" below).
- `routes/api.php` — registers `POST /api/send-whatsapp` → the controller
  above. (A fresh Laravel 11+/12+/13+ skeleton doesn't ship this file by
  default; it has to be created and wired into `bootstrap/app.php`.)
- `config/services.php` — added a `wasender` block reading `WASENDER_API_KEY`
  and `WASENDER_SHARED_SECRET` from `.env`. Everything else in this file is
  stock.
- `bootstrap/app.php` — one added line (`api: __DIR__.'/../routes/api.php',`)
  in the `withRouting()` call, to actually load `routes/api.php`. Everything
  else is stock.
- `tests/Feature/SendWhatsAppTest.php` — feature tests using `Http::fake()`
  to mock the outbound call to WasenderAPI (no real network needed to run
  `php artisan test`). Covers: happy path, missing-field validation (422),
  unconfigured key (500), wrong shared secret (401).

## Setup

```
composer install
cp .env.example .env
php artisan key:generate
```

Edit `.env`:
```
WASENDER_API_KEY=your_session_api_key_here
# optional:
WASENDER_SHARED_SECRET=pick-a-long-random-string
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
```

## Deploying

See `README.md` for the full Laravel Forge walkthrough (create site, connect
Git repo, set env vars, deploy, SSL, connect the resulting URL to Paybill's
Settings page). Any other Laravel host works too — there's nothing
Forge-specific in the code itself, Forge is just the deploy target this was
built for.

## Security

Without `WASENDER_SHARED_SECRET` set, anyone who discovers this endpoint's
URL can POST to it and send WhatsApp messages through your connected
session — there's no auth on the Paybill frontend to piggyback on. Set
`WASENDER_SHARED_SECRET` in `.env` and put the same value as `?key=...` on
the URL you paste into Paybill's Settings → Backend endpoint URL. The `api/*`
route group is also throttled by Laravel's default rate limiter
(60 req/min) as a second layer.

## Related project

`paybill-kanban-dashboard/` (delivered separately, own git history) is the
React frontend that calls this endpoint from `src/lib/whatsapp.ts`. If you
change this controller's request/response shape, update that file to match.

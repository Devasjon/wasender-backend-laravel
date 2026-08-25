# Paybill WhatsApp Bridge (Laravel)

A small Laravel API backing the Paybill frontend (a React app — see the
sibling `paybill-kanban-dashboard` project):

1. Real per-user accounts (Laravel Sanctum) — the whole frontend is gated
   behind login, email-verified via a Resend OTP.
2. Keep the WasenderAPI Session API Key secret on a server, so the frontend
   can trigger WhatsApp notifications without ever holding that key itself.
3. Store each user's full app state (bills, debts, settings) so it's the
   same on every device instead of living only in one browser's memory.
4. Send automatic daily WhatsApp reminders for bills due soon or overdue.

Meant to be deployed via Laravel Forge, but runs anywhere Laravel runs.

This is a fresh `laravel/laravel` skeleton (framework `^13.17` at time of
writing) with a handful of files changed/added from stock, plus
`laravel/sanctum`. Everything else is the standard Laravel scaffold. This
app needs a database (SQLite by default, per `.env.example`'s
`DB_CONNECTION=sqlite`).

## What's actually custom here

Auth (real per-user accounts, Sanctum personal-access-token / bearer mode —
not cookie-based, no CSRF needed):
- `app/Http/Controllers/Api/Auth/` — `RegisterController` (creates an
  unverified user, sends a register OTP; re-registering an unverified email
  is idempotent), `VerifyOtpController` (confirms the OTP, marks the user
  verified via `forceFill` since `email_verified_at` is deliberately not in
  `User`'s Fillable list, issues a token), `ResendOtpController`,
  `LoginController` (generic 401 on bad credentials — no email enumeration;
  403 `email_not_verified` if unverified), `LogoutController` (revokes the
  current token), `MeController` (session validation for the frontend's
  `AuthGate`), `ForgotPasswordController` (always returns the same generic
  response, sends nothing for an unknown email — no enumeration),
  `ResetPasswordController` (revokes **all** the user's tokens on success).
- `app/Services/OtpService.php` — generates/verifies 6-digit codes (`otps`
  table, `purpose` column distinguishes `register` vs `reset`). 10-minute
  expiry, 5-attempt lockout. Also logs the plaintext code under **both**
  `local` and `testing` environments (`Log::info`) purely so a developer (or
  a feature test via `Log::spy()`) can read it without a real inbox — codes
  are otherwise only ever stored hashed.
- `app/Services/ResendMailer.php` — sends OTP emails via the Resend HTTP API
  (`POST https://api.resend.com/emails`), same `{ok, status, body}` shape as
  `WasenderMessenger.php` below.
- Routes: `/api/auth/*` in `routes/api.php`, each with its own named rate
  limiter registered in `AppServiceProvider::boot()` (tighter than the
  default 60/min — these are brute-force targets). Note
  `auth-otp-verify`'s limit (10/10min) is deliberately set *above*
  `OtpService`'s own 5-attempt lockout, so the app-level lockout (clearer
  error) fires first; the route throttle is just the outer guard.
- `/api/state`, `/api/scan-receipt`, `/api/send-whatsapp` all require
  `auth:sanctum` now (see routes/api.php) — the old shared-secret
  `EnsurePaybillAccessToken` middleware and `paybill.token` alias are gone.

WhatsApp bridge:
- `app/Http/Controllers/Api/SendWhatsAppController.php` — `POST` with
  `{ to, text }` in the body → validates → forwards to
  `https://www.wasenderapi.com/api/send-message` via
  `app/Services/WasenderMessenger.php` (shared with the reminders command
  below) → returns `{ ok: true, wasender: {...} }` or `{ error, detail }`.
  Requires `auth:sanctum`.
- `tests/Feature/SendWhatsAppTest.php`.

Automatic reminders:
- `app/Console/Commands/SendBillReminders.php` — runs daily (see
  `routes/console.php`, needs Forge's Scheduler toggled on). Iterates
  **every user's** `AppState` row (not a single global row), sends a
  "due soon"/"overdue" WhatsApp reminder per unpaid bill via
  `WasenderMessenger`, deduped per (user, bill, event, day) via
  `app/Models/ReminderLog.php`.
- `tests/Feature/SendBillRemindersTest.php`.

Cloud sync (multi-device persistence, now per-user):
- `database/migrations/2026_01_01_000000_create_app_states_table.php` +
  `..._add_user_id_to_app_states_table.php` +
  `..._drop_owner_from_app_states_table.php` — `app_states` now has one row
  per `user_id` (unique), not a single hardcoded `owner = "default"` row.
  The two-step migration (add nullable `user_id`, drop `owner` in a
  *separate* later migration) exists so a real deployment's one live
  `owner="default"` row could be exported via the frontend's Settings →
  Export Data before the column disappeared — see git history for that
  migration path if you're doing this again elsewhere.
- `app/Models/AppState.php` — `data` cast to array, `Fillable(['user_id',
  'data'])`.
- `app/Http/Controllers/Api/AppStateController.php` — `GET`/`PUT
  /api/state` scoped to `$request->user()->id`. Requires `auth:sanctum`.
- `tests/Feature/AppStateTest.php` — includes a cross-user isolation test.

Receipt/invoice scanning:
- `app/Http/Controllers/Api/ScanReceiptController.php` — unchanged behavior,
  now behind `auth:sanctum` instead of the old shared token.

## Setup

```
composer install
cp .env.example .env
php artisan key:generate
php artisan install:api   # only needed once, already done in this repo — see composer.json for what it added
php artisan migrate
```

Edit `.env`:
```
WASENDER_API_KEY=your_session_api_key_here

RESEND_API_KEY=your_resend_api_key
RESEND_FROM_EMAIL=noreply@yourdomain.com   # must be on a Resend-verified domain

SANCTUM_EXPIRATION=43200   # minutes a login session stays valid (30 days); blank = never
```

`WASENDER_API_KEY` is the per-session **Session API Key** from the
WasenderAPI dashboard — not the account-level Personal Access Token.

Run locally:
```
php artisan serve
```

Test:
```
php artisan test

curl -X POST http://127.0.0.1:8000/api/auth/register \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"name":"Test","email":"test@example.com","password":"password123","password_confirmation":"password123"}'
# OTP code is printed to storage/logs/laravel.log in local/testing envs

curl -X POST http://127.0.0.1:8000/api/auth/verify-otp \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"email":"test@example.com","code":"123456"}'
# returns { token, user } — use the token as a Bearer header below

curl http://127.0.0.1:8000/api/state -H "Authorization: Bearer <token>"
```

## Deploying

See `README.md` for the full Laravel Forge walkthrough. Any other Laravel
host works too — nothing Forge-specific in the code, it's just the deploy
target this was built for.

## Security

Every `/api/state`, `/api/scan-receipt`, and `/api/send-whatsapp` request
requires a valid Sanctum bearer token tied to a real user — there's no
shared/global secret anymore. `/api/auth/forgot-password` and
`/api/auth/resend-otp` deliberately return the same generic response
whether or not the email exists, to avoid leaking account existence.
Password resets revoke every existing token for that user.

The `api/*` route group is also throttled by Laravel's default rate limiter
(60 req/min) as a second layer beyond the auth-specific limiters above.

## Related project

`paybill-kanban-dashboard/` (delivered separately, own git history) is the
React frontend. `src/lib/auth.ts` calls `/api/auth/*`; `src/lib/whatsapp.ts`
and `src/lib/cloudSync.ts` and `src/lib/receiptScan.ts` all send the logged-in
user's session token as a Bearer header now. If you change any controller's
request/response shape, update the matching frontend file.

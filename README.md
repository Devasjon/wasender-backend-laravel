# Paybill backend (Laravel + Laravel Forge)

Backs the Paybill frontend end to end: (1) real per-user accounts (Laravel
Sanctum, email verification via a Resend OTP) — the whole frontend is gated
behind login; (2) keeps the WasenderAPI Session API Key secret on a server,
so Paybill can trigger WhatsApp notifications without ever holding that key
itself; (3) stores each user's full app state (bills, debts, settings) in a
database so it's the same on every device; (4) sends automatic daily
WhatsApp reminders for bills due soon or overdue. This is a complete,
ready-to-run Laravel project (a stock `laravel/laravel` skeleton with
Sanctum and a handful of files added — see `CLAUDE.md` for exactly what's
custom) — not a partial file patch. Setup takes about 15 minutes.

## 1. Install dependencies

```
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
```

`php artisan migrate` creates the `app_states` table used for cloud sync
(SQLite by default — `database/database.sqlite` is created automatically by
the migrate command if it doesn't exist yet).

## 2. Add your keys to `.env`

Open `.env` and fill in:

```
WASENDER_API_KEY=your_session_api_key_here

# Registration/reset OTP email — get a key at https://resend.com/api-keys.
# RESEND_FROM_EMAIL must be an address on a domain you've verified in Resend.
RESEND_API_KEY=your_resend_api_key
RESEND_FROM_EMAIL=noreply@yourdomain.com

# How long a login session stays valid, in minutes. 43200 = 30 days.
SANCTUM_EXPIRATION=43200
```

`WASENDER_API_KEY` is the **Session API Key** from your connected WhatsApp
session in the WasenderAPI dashboard (not the Personal Access Token).
There's no shared access token to configure anymore — every endpoint is
protected by each user's own login session instead (see step 7).

## 3. Test locally before deploying (optional but recommended)

```
php artisan serve
```

In another terminal, register an account, verify it, and use the returned
token:

```
curl -X POST "http://127.0.0.1:8000/api/auth/register" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"name":"Test","email":"test@example.com","password":"password123","password_confirmation":"password123"}'

# The verification code is printed to storage/logs/laravel.log when
# APP_ENV=local (no real inbox needed for this local test):
tail -n5 storage/logs/laravel.log

curl -X POST "http://127.0.0.1:8000/api/auth/verify-otp" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"email":"test@example.com","code":"123456"}'
# → { "ok": true, "token": "1|xxxxx...", "user": {...} }

curl -X POST "http://127.0.0.1:8000/api/send-whatsapp" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer 1|xxxxx..." \
  -d '{"to": "+60123456789", "text": "Ujian dari Laravel"}'

curl -X PUT "http://127.0.0.1:8000/api/state" \
  -H "Authorization: Bearer 1|xxxxx..." \
  -H "Content-Type: application/json" \
  -d '{"data": {"bills": [], "debts": []}}'

curl "http://127.0.0.1:8000/api/state" \
  -H "Authorization: Bearer 1|xxxxx..."
```

You should get `{"ok":true,...}` back from the WhatsApp test (and a WhatsApp
message should arrive), and the `/api/state` calls should save then echo
back `{"bills":[],"debts":[]}`.

## 4. Push to a Git repository

Forge deploys from Git (GitHub, GitLab, or Bitbucket). This project already
has a local git history (one commit) — just create a repo and push it:

```
git remote add origin git@github.com:your-username/paybill-wasender-backend.git
git branch -M main
git push -u origin main
```

## 5. Deploy on Laravel Forge

1. Log in to Forge (forge.laravel.com) and connect a server (or use an
   existing one) — any size works, this endpoint is very lightweight.
2. **Create Site** → enter a domain (or use the Forge-provided subdomain if
   you don't have one yet) → Web Directory should stay `/public` (Laravel
   default) → Project Type: **Laravel**.
3. This app now uses a real database (SQLite) for cloud sync, so before
   finishing site creation, open **Advanced settings** and **turn off
   "Zero-downtime deployments"**. With it off, deploys update the same
   directory in place, so `database/database.sqlite` just persists there
   naturally between deploys — no extra config needed. (If you'd rather
   keep zero-downtime deploys on, Forge supports this via "Shared paths" —
   add a shared path from `database.sqlite` to `database/database.sqlite`
   in the site's deployment settings so the file survives across release
   directories. Turning it off is simpler and plenty for a lightweight
   single-user API like this one.)
4. **Git Repository** tab on the site → connect the repo from step 4, branch
   `main` → enable **Install Composer Dependencies** → Forge will run
   `composer install` automatically on deploy.
5. **Environment** tab → paste the contents of your local `.env` (including
   `APP_KEY`, `WASENDER_API_KEY`, `RESEND_API_KEY`, `RESEND_FROM_EMAIL`,
   `SANCTUM_EXPIRATION`) → Save.
6. **Deployment** tab → edit the deploy script so the SQLite file is created
   the first time, and migrations run on every deploy:
   ```
   cd $FORGE_SITE_PATH
   git pull origin $FORGE_SITE_BRANCH
   $FORGE_COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader
   touch database/database.sqlite
   $FORGE_PHP artisan migrate --force
   ```
   (Forge pre-fills most of this — you're mainly adding the `touch` line
   before the `migrate` line.)
7. Click **Deploy Now**.
8. **SSL** tab → request a free **Let's Encrypt** certificate for the domain
   so the endpoint is served over HTTPS (required — Paybill's frontend runs
   over HTTPS/file access and mixed-content or plain-HTTP POSTs from a
   deployed page may be blocked by the browser).
9. (Optional) Toggle **Quick Deploy** so future `git push` to `main`
   auto-deploys.

Your endpoints are now live at:
```
https://your-domain.com/api/send-whatsapp
https://your-domain.com/api/state
```

## 6. Test the live endpoints

Register + verify an account against the live URL (same as the local test
in step 3, but you'll need a real inbox now — `RESEND_FROM_EMAIL` must
actually deliver):

```
curl -X POST "https://your-domain.com/api/auth/register" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"name":"Test","email":"you@yourdomain.com","password":"password123","password_confirmation":"password123"}'

curl -X POST "https://your-domain.com/api/auth/verify-otp" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"email":"you@yourdomain.com","code":"the-code-from-your-inbox"}'
# → { "ok": true, "token": "1|xxxxx...", "user": {...} }

curl -X POST "https://your-domain.com/api/send-whatsapp" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer 1|xxxxx..." \
  -d '{"to": "+60123456789", "text": "Ujian dari Forge"}'

curl "https://your-domain.com/api/state" \
  -H "Authorization: Bearer 1|xxxxx..."
```

## 7. Connect it to Paybill

Unlike before, there's nothing to paste into Paybill's Settings page — every
user's session token is issued automatically at login, and there's only one
backend URL to configure, at **build time**, not per-user:

1. In the Paybill frontend project, set `VITE_API_BASE_URL=https://your-domain.com`
   (see its own `.env.example`) and rebuild/redeploy the frontend.
2. Open the deployed Paybill site → **Register** an account → check your
   email for the verification code → verify → you're in.
3. **Settings** → **WhatsApp Notifications** card → set your **Default
   WhatsApp number** (E.164, `+60...`) → turn on **Enable WhatsApp
   notifications** → save. Add or pay a bill and check the **Notification
   Log** — it should show `Sent`.
4. Cloud Sync is always on once logged in — no toggle needed. Log into the
   same account on a second device/browser and the same bills and debts
   show up there too.

## 8. Automatic due/overdue reminders (optional)

This backend can also send WhatsApp reminders on its own — once a day, for
any unpaid bill that's due within 3 days or already overdue, for **every**
registered user — without Paybill needing to be open anywhere. It works off
each user's synced state, so a given user only has anything to check once
they've logged in and synced at least once.

1. On the Forge site → **Scheduler** tab → toggle it **on**. This makes
   Forge run `php artisan schedule:run` every minute in the background (the
   reminder command itself still only actually sends anything once a day —
   see `routes/console.php` for the exact time, default 09:00 server time).
2. That's it — no extra `.env` values needed, it reuses `WASENDER_API_KEY`
   and reads `whatsapp.enabled` / `whatsapp.defaultNumber` from whatever's
   currently synced. If a bill gets marked paid (or WhatsApp notifications
   get turned off) on any device, the next scheduled run picks that up
   automatically and stops reminding for it.
3. To test without waiting for the schedule, SSH in (or use Forge's
   **Commands** tab) and run it directly:
   ```
   php artisan bills:send-reminders
   ```
   It prints what it sent/skipped and why. Each bill only gets one
   "due soon" and one "overdue" reminder per calendar day — check the
   `reminder_logs` table if you need to see exactly what already went out.

## Securing the endpoints

Every endpoint — WhatsApp send, cloud sync, receipt scanning — requires a
valid Sanctum bearer token tied to a real, email-verified user account.
There's no shared secret to configure or leak: each user only ever sees and
sends through their own data. Registration/login/OTP endpoints have their
own tighter rate limits (see `app/Providers/AppServiceProvider.php`) on top
of Laravel's default `api/*` throttle (60 requests/minute), since those are
brute-force targets. Password resets revoke every existing session for that
user.

## Notes and limits

- The WhatsApp endpoint's request/response shape (`POST { to, text }` in,
  `{ ok: true, ... }` or `{ error, detail }` out) is unchanged — only its
  auth (Sanctum bearer token instead of a shared secret) is new.
- WasenderAPI trial sessions are rate-limited (~1 message/minute, 50/day);
  paid sessions allow much more. A `Failed` entry in Paybill's Notification
  Log usually means a WasenderAPI-side rate limit or a disconnected session
  — check the WasenderAPI dashboard first.
- Phone numbers must be E.164 (`+` + country code + number, no spaces or
  dashes).
- Cloud sync is genuinely multi-user now — one `app_states` row per
  `user_id`, not a single shared blob. Every registered user gets their own
  isolated bills/debts/settings on this same backend.
- Forge servers cost money (or use a server you already pay for). Resend's
  free tier covers OTP email volume for personal use; check its pricing if
  you expect many registrations.

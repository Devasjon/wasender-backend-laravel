# Paybill ↔ WasenderAPI backend (Laravel + Laravel Forge)

Two jobs: (1) keeps the WasenderAPI Session API Key secret on a server, and
gives Paybill a URL it can POST `{ to, text }` to whenever a bill's status
changes; (2) stores Paybill's full app state (bills, debts, settings) in a
database so it's the same on every device instead of living only in one
browser's memory. This is a complete, ready-to-run Laravel project (a stock
`laravel/laravel` skeleton with a handful of files added — see `CLAUDE.md`
for exactly what's custom) — not a partial file patch. Setup takes about
10 minutes.

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

# Optional but recommended — see "Securing the endpoints" below
WASENDER_SHARED_SECRET=pick-a-long-random-string

# Required for cloud sync (/api/state) — generate one with:
#   php artisan tinker --execute="echo Str::random(40);"
APP_ACCESS_TOKEN=paste-a-generated-token-here
```

`WASENDER_API_KEY` is the **Session API Key** from your connected WhatsApp
session in the WasenderAPI dashboard (not the Personal Access Token).
`APP_ACCESS_TOKEN` is a token you generate once and paste into two places:
here, and Paybill's Settings → Cloud Sync → Access Token field.

## 3. Test locally before deploying (optional but recommended)

```
php artisan serve
```

In another terminal:

```
curl -X POST "http://127.0.0.1:8000/api/send-whatsapp" \
  -H "Content-Type: application/json" \
  -d '{"to": "+60123456789", "text": "Ujian dari Laravel"}'

curl -X PUT "http://127.0.0.1:8000/api/state" \
  -H "Authorization: Bearer paste-a-generated-token-here" \
  -H "Content-Type: application/json" \
  -d '{"data": {"bills": [], "debts": []}}'

curl "http://127.0.0.1:8000/api/state" \
  -H "Authorization: Bearer paste-a-generated-token-here"
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
   `APP_KEY`, `WASENDER_API_KEY`, `WASENDER_SHARED_SECRET`,
   `APP_ACCESS_TOKEN`) → Save.
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

```
curl -X POST "https://your-domain.com/api/send-whatsapp" \
  -H "Content-Type: application/json" \
  -d '{"to": "+60123456789", "text": "Ujian dari Forge"}'
```

If you set `WASENDER_SHARED_SECRET`, add it as a query parameter:

```
curl -X POST "https://your-domain.com/api/send-whatsapp?key=pick-a-long-random-string" \
  -H "Content-Type: application/json" \
  -d '{"to": "+60123456789", "text": "Ujian dari Forge"}'
```

And the cloud-sync endpoint (always requires the bearer token — there's no
optional mode for this one):

```
curl "https://your-domain.com/api/state" \
  -H "Authorization: Bearer paste-a-generated-token-here"
```

## 7. Connect it to Paybill

1. Open Paybill → **Settings**.
2. **WhatsApp Notifications** card → **Backend endpoint URL**:
   `https://your-domain.com/api/send-whatsapp` (append `?key=...` if you
   set `WASENDER_SHARED_SECRET`) → **Default WhatsApp number** in E.164
   format (`+60...`) → turn on **Enable WhatsApp notifications** → save.
   Add or pay a bill and check the **Notification Log** — it should show
   `Sent`.
3. **Cloud Sync** card → **Sync URL**: `https://your-domain.com/api/state`
   → **Access Token**: the `APP_ACCESS_TOKEN` value from `.env` → turn on
   **Enable Cloud Sync** → save. Open Paybill on a second device/browser
   with the same Sync URL and token configured — the same bills and debts
   should now show up there too.

## Securing the endpoints

Without `WASENDER_SHARED_SECRET` set, anyone who discovers the WhatsApp
endpoint's URL could POST to it and send messages through your session.
Since Paybill's frontend has no login system of its own, the simplest
protection is the `?key=...` query-string check already built into
`SendWhatsAppController` — just set `WASENDER_SHARED_SECRET` in `.env` and
include the same value in the URL you paste into Paybill's Settings.

`/api/state` is more sensitive — it holds your actual bill and debt data —
so it isn't optional there: `APP_ACCESS_TOKEN` **must** be set in `.env` or
the endpoint refuses all requests with a 500 rather than silently running
open. Treat that token like a password; anyone who has it can read and
overwrite your synced data.

Laravel also throttles the `api/*` route group by default
(60 requests/minute) as a second layer against abuse on both endpoints.

## Notes and limits

- The WhatsApp endpoint mirrors the exact request/response shape the
  Vercel version used (`POST { to, text }` in, `{ ok: true, ... }` or
  `{ error, detail }` out) — nothing on the Paybill frontend needed to
  change for that part, only the URL in Settings.
- WasenderAPI trial sessions are rate-limited (~1 message/minute, 50/day);
  paid sessions allow much more. A `Failed` entry in Paybill's Notification
  Log usually means a WasenderAPI-side rate limit or a disconnected session
  — check the WasenderAPI dashboard first.
- Phone numbers must be E.164 (`+` + country code + number, no spaces or
  dashes).
- Cloud sync stores one JSON blob per install (single-user app, no
  multi-account support) — if you ever need multiple separate Paybill
  "accounts" sharing this backend, `app_states.owner` is already a distinct
  column ready to key off something other than the hardcoded `"default"`.
- Forge servers cost money (or use a server you already pay for). If cost
  matters more than having your own server, the earlier n8n workflow guide
  covers the WhatsApp half without a server of your own — but cloud sync
  specifically needs somewhere to persist a database, so a server (Forge or
  otherwise) is the more direct fit for that part.

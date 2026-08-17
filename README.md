# Paybill ↔ WasenderAPI backend (Laravel + Laravel Forge)

Keeps the WasenderAPI Session API Key secret on a server, and gives Paybill
a URL it can POST `{ to, text }` to whenever a bill's status changes. This
is a complete, ready-to-run Laravel project (a stock `laravel/laravel`
skeleton with one endpoint added — see `CLAUDE.md` for exactly what's
custom) — not a partial file patch. Setup takes about 10 minutes.

## 1. Install dependencies

```
composer install
cp .env.example .env
php artisan key:generate
```

## 2. Add your WasenderAPI key to `.env`

Open `.env` and fill in:

```
WASENDER_API_KEY=your_session_api_key_here

# Optional but recommended — see "Securing the endpoint" below
WASENDER_SHARED_SECRET=pick-a-long-random-string
```

`WASENDER_API_KEY` is the **Session API Key** from your connected WhatsApp
session in the WasenderAPI dashboard (not the Personal Access Token).

## 3. Test locally before deploying (optional but recommended)

```
php artisan serve
```

In another terminal:

```
curl -X POST "http://127.0.0.1:8000/api/send-whatsapp" \
  -H "Content-Type: application/json" \
  -d '{"to": "+60123456789", "text": "Ujian dari Laravel"}'
```

You should get `{"ok":true,...}` back and a WhatsApp message should arrive.

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
3. **Git Repository** tab on the site → connect the repo from step 4, branch
   `main` → enable **Install Composer Dependencies** → Forge will run
   `composer install` automatically on deploy.
4. **Environment** tab → paste the contents of your local `.env` (including
   `APP_KEY`, `WASENDER_API_KEY`, `WASENDER_SHARED_SECRET`) → Save.
5. Click **Deploy Now**. Forge's default Laravel deploy script (composer
   install, `artisan migrate --force`, cache clear) runs automatically —
   this app has no database, so the migrate step is a harmless no-op.
6. **SSL** tab → request a free **Let's Encrypt** certificate for the domain
   so the endpoint is served over HTTPS (required — Paybill's frontend runs
   over HTTPS/file access and mixed-content or plain-HTTP POSTs from a
   deployed page may be blocked by the browser).
7. (Optional) Toggle **Quick Deploy** so future `git push` to `main`
   auto-deploys.

Your endpoint is now live at:
```
https://your-domain.com/api/send-whatsapp
```

## 6. Test the live endpoint

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

## 7. Connect it to Paybill

1. Open Paybill → **Settings**.
2. **Backend endpoint URL**: `https://your-domain.com/api/send-whatsapp`
   (append `?key=...` if you set `WASENDER_SHARED_SECRET`).
3. **Default WhatsApp number**: your number in E.164 format (`+60...`).
4. Turn on **Enable WhatsApp notifications** → **Save settings**.
5. Add or pay a bill in Paybill and check the **Notification Log** at the
   bottom of Settings — it should show `Sent`.

## Securing the endpoint

Without `WASENDER_SHARED_SECRET` set, anyone who discovers this URL could
POST to it and send WhatsApp messages through your session. Since Paybill's
frontend has no login system of its own, the simplest protection is the
`?key=...` query-string check already built into
`SendWhatsAppController` — just set `WASENDER_SHARED_SECRET` in `.env` and
include the same value in the URL you paste into Paybill's Settings. Laravel
also throttles the `api/*` route group by default (60 requests/minute), which
adds a second layer against abuse.

## Notes and limits

- This mirrors the exact request/response shape the Vercel version used
  (`POST { to, text }` in, `{ ok: true, ... }` or `{ error, detail }` out),
  so nothing on the Paybill frontend needs to change — only the URL in
  Settings.
- WasenderAPI trial sessions are rate-limited (~1 message/minute, 50/day);
  paid sessions allow much more. A `Failed` entry in Paybill's Notification
  Log usually means a WasenderAPI-side rate limit or a disconnected session
  — check the WasenderAPI dashboard first.
- Phone numbers must be E.164 (`+` + country code + number, no spaces or
  dashes).
- Forge servers cost money (or use a server you already pay for). If cost
  matters more than having your own server, the earlier n8n workflow guide
  is worth a second look — it needs no server of your own.

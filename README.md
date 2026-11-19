# WhatsApp Personal Assistant

A lightweight personal assistant bot powered by Laravel, Google Gemini API, Meta WhatsApp Cloud API, and Telegram (for backup).

---

## Architecture & Flow

```
WhatsApp User
     │
     ▼ (POST /api/wk/wp)
Webhook Signature Validation (HMAC-SHA256)
     │
     ▼
Sender Whitelist Check (ALLOW_MESSAGE_ONLY_FROM)
     │
     ├─► Telegram Forwarding (Mirrors text, media, location, contacts)
     │
     ▼
Google Gemini (Structured Intent Extraction)
     │
     ├─► 'addReminder'    ──► Saves reminder (one-off date or cron expression)
     ├─► 'addTransaction' ──► Records income, expense, or lent/borrowed loan
     └─► 'chat'           ──► Contextual assistant reply
     │
     ▼
WhatsApp Reply (Text response + interactive action buttons)
```

**Background Scheduler:**
* Laravel scheduler runs `php artisan reminders:dispatch-due` every 2 minutes to check due reminders and dispatch WhatsApp alerts.

---

## Features

* **Restricted Access:** Strictly filters incoming messages by phone number (`ALLOW_MESSAGE_ONLY_FROM`).
* **Natural Language Reminders:** Creates one-off reminders or recurring cron jobs (e.g., *"remind to call mom tomorrow at 10am"*, *"remind every Saturday at 6pm to wash car"*).
* **Expense & Loan Tracker:** Categorizes income, expenses, and loans (lent/borrowed) with auto-generated delete action buttons.
* **Telegram Mirroring:** Forwards all WhatsApp inbound messages, attachments, audio, and documents to Telegram for backup.
* **Interactive Messages:** Sends WhatsApp interactive buttons to deactivate/reactivate reminders or delete transactions inline.

---

## Requirements

* PHP 8.2+
* Composer
* SQLite or MySQL
* HTTPS endpoint (Domain with SSL or ngrok/Cloudflare Tunnel for local testing)

---

## Installation

```bash
git clone <repository-url> wa-assistant
cd wa-assistant

composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
```

---

## Configuration (`.env`)

Set the following environment variables:

### 1. WhatsApp Cloud API (Meta for Developers)
| Key | Description |
|---|---|
| `WHATSAPP_TOKEN` | WhatsApp Cloud API Access Token (System User permanent token recommended) |
| `WHATSAPP_PHONE_NUMBER_ID` | Phone Number ID from WhatsApp App Dashboard |
| `WHATSAPP_VERIFY_TOKEN` | Arbitrary secret string defined by you for webhook handshake |
| `WHATSAPP_APP_SECRET` | Meta App Secret (used to verify `X-Hub-Signature-256`) |
| `ALLOW_MESSAGE_ONLY_FROM` | Allowed sender phone number with country code (e.g., `919876543210`) |

### 2. Google Gemini API
| Key | Description |
|---|---|
| `GEMINI_API_KEY` | API key from Google AI Studio |

### 3. Telegram (Message Backup)
| Key | Description |
|---|---|
| `TELEGRAM_BOT_TOKEN` | Telegram bot token from [@BotFather](https://t.me/BotFather) |
| `TELEGRAM_CHAT_ID` | Telegram chat ID or topic ID (e.g. `123456789` or `-100123456_99`) |

---

## Webhook Setup (Meta Developer Portal)

1. Navigate to **WhatsApp > Configuration** in your Meta App Dashboard.
2. Under **Webhook**, click **Edit**:
   * **Callback URL**: `https://your-domain.com/api/wk/wp`
   * **Verify Token**: Same value as `WHATSAPP_VERIFY_TOKEN` in `.env`
3. Click **Verify and Save**.
4. Under **Webhook Fields**, subscribe to **`messages`**.

---

## Scheduler Setup

Add the Laravel schedule runner to your server's crontab:

```bash
* * * * * cd /path/to/project && php artisan schedule:run >> /dev/null 2>&1
```

For local testing:
```bash
php artisan schedule:work
```

---

## Security Notes

* **Web Server DocumentRoot:** Ensure your web server points directly to the `public/` directory so that `.env` and `database.sqlite` are never exposed to the web.
* **Debug Mode:** Always ensure `APP_DEBUG=false` in production environments.

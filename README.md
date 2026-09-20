# SeaPass — Web App & API

The **Laravel 12** backend for SeaPass: a RESTful JSON API plus the web admin dashboard for the
maritime passenger ticketing system (Surigao ⇄ San Jose lancha route).

This repository is the `web/` half of the project. The Flutter passenger app lives in
[nessmark/SEAPASS-Mobile](https://github.com/nessmark/SEAPASS-Mobile); both are split out of the
[nessmark/seapass](https://github.com/nessmark/seapass) monorepo.

## Stack
- Laravel 12, PHP 8.2+, MySQL 8+ / MariaDB 10.5+
- Vite for frontend asset bundling
- Sanctum token authentication for the mobile API
- PayMongo payments, QR ticket verification, email notifications (OTP, bookings, advisories, reschedules)

## Requirements
- **PHP** 8.2 or 8.3 with `pdo_mysql`, `curl`, `mbstring`, `openssl`, `fileinfo`, `sodium`
- **Composer** 2.6+
- **MySQL** 8.0+ / MariaDB 10.5+
- **Node.js** LTS (v18 or v20+)
- Windows 10/11 is the documented host OS; the app itself runs anywhere PHP does.

## Setup

```powershell
composer install
npm install
npm run build
Copy-Item .env.example .env   # then edit DB credentials
php artisan key:generate
php artisan migrate
php artisan serve --host=0.0.0.0 --port=8000
```

`.env` database block:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=seapass_db
DB_USERNAME=root
DB_PASSWORD=your_mysql_password
```

Bind with `--host=0.0.0.0` so Android phones on the same Wi-Fi can reach the API on port 8000.

> `php artisan migrate --seed` only creates 2 accounts (`admin@gmail.com` / `admin123`,
> `test@example.com` / `password`) and 2 fare rows. No boats, trips or bookings are seeded — a demo
> starts on an empty dashboard until they are entered in the admin panel or added to `DatabaseSeeder`.

## Windows helper scripts (`scripts/`)

| Script | What it does |
|---|---|
| `setup_firewall.bat` | Run as Administrator once — opens inbound TCP 8000 in Windows Defender Firewall. |
| `start_server.bat` | Frees port 8000, sets `adb reverse tcp:8000 tcp:8000` for a USB-connected phone, starts an **ngrok** tunnel on the project's free domain, then runs `php artisan serve` on 127.0.0.1:8000. |
| `connect_usb.bat` | Just the `adb reverse tcp:8000 tcp:8000` step, for a phone plugged in after the server started. |

> `start_server.bat` depends on `ngrok` being installed and in PATH, and on that free ngrok domain
> staying alive. For a LAN-only run, skip it and use `php artisan serve --host=0.0.0.0 --port=8000`.

## Background workers

Two things do **not** run inside `artisan serve` and need their own processes:

```powershell
php artisan queue:work        # advisory / booking / OTP emails are queued
php artisan schedule:work     # trip status sync + recurring schedule generation
```

On a server, `schedule:work` is replaced by the usual one-line cron entry for `schedule:run`.

## Tests

```powershell
php artisan test
```

The suite runs on SQLite in memory, so the `pdo_sqlite` / `php-sqlite3` extension must be enabled.

## Structure

- `app/Http/Controllers/` — API + admin controllers
- `app/Services/` — booking, PayMongo, recurring-schedule, and trip-status services
- `app/Models/` — Eloquent models
- `database/migrations/` — schema migrations
- `resources/views/` — admin dashboard Blade views + emails
- `routes/api.php` — mobile API endpoints; `routes/web.php` — admin panel routes
- `scripts/` — Windows helper batch files (above)

See [MODULAR_STRUCTURE.md](MODULAR_STRUCTURE.md) for the module-by-module breakdown.

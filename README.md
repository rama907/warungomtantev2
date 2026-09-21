# Warung Om Tante V2 — Employee & Operations Management System

A web-based system for managing staff and daily operations of a food business: duty and attendance tracking, leave / resignation / manual-duty requests with approval, payroll and payslips, warnings, sales and stock records, with Discord notifications and role-based access.

[![PHP](https://img.shields.io/badge/PHP-777BB4?logo=php&logoColor=white)](#tech-stack)
[![MySQL](https://img.shields.io/badge/MySQL-4479A1?logo=mysql&logoColor=white)](#tech-stack)
[![Next.js](https://img.shields.io/badge/Next.js-000000?logo=nextdotjs&logoColor=white)](#tech-stack)
[![Tailwind](https://img.shields.io/badge/Tailwind_CSS-06B6D4?logo=tailwindcss&logoColor=white)](#tech-stack)
[![Portfolio](https://img.shields.io/badge/Case%20study-ramahrinaldi.id-f59e0b)](https://ramahrinaldi.id/projects/warung-om-tante/)

**Live demo (simulation with dummy data):** https://ramahrinaldi.id/demo/warung-om-tante/
**Case study:** https://ramahrinaldi.id/projects/warung-om-tante/

## Features

**Attendance and duty**
- Clock in / clock out with duty history and admin-side correction
- Manual duty requests with approval
- Daily duty and absence recaps (cron jobs)

**Requests and approval workflow**
- Leave requests
- Resignation requests
- New-employee requests and password reset requests
- Central page for reviewing all requests

**Payroll**
- Weekly salary recap and automatic weekly backup
- Personal payslip and payslip generation
- Company bank / income report

**People management**
- Employee directory and organization chart
- Warning management and personal warning history
- Suggestions box
- Role-based access (admin / manager / staff)

**Operations**
- Sales records, warehouse stock, refrigerator stock and cooking data
- Booking management
- Discord notifications for important events

## Tech stack

PHP · MySQL (MariaDB) · Next.js · Tailwind CSS · TypeScript · JavaScript · Discord webhooks

## Project layout

```
.                 PHP pages (dashboard, requests, payroll, reports)
api/              JSON endpoints and cron scripts
app/, components/ Next.js / Tailwind interface
includes/, lib/   shared helpers
scripts/          maintenance scripts
config.example.php   configuration template (copy to config.php)
database.schema.sql  database schema (no data, no accounts)
```

## Getting started

1. Copy `config.example.php` to `config.php` and fill in the database, API key, cron key and Discord webhook values.
2. Import `database.schema.sql` into MySQL / MariaDB.
3. Create the first admin account with a strong password. A hash can be generated with:
   ```
   php -r "echo password_hash('CHANGE_THIS_PASSWORD', PASSWORD_DEFAULT);"
   ```
4. Set `CRON_SECRET_KEY` and `DEFAULT_EMPLOYEE_PASSWORD` in `config.php`. If `DEFAULT_EMPLOYEE_PASSWORD` is empty, a random password is generated for new accounts.
5. Schedule the cron scripts (`daily-duty-recap-cron.php`, `daily-absent-recap-cron.php`, `api/backup_weekly_salary.php`).
6. Keep `display_errors` off in production.

## Security

What the code does:
- SQL uses prepared statements; passwords are stored with `password_hash()` and checked with `password_verify()`.
- The session ID is regenerated after login, and the session cookie is set `HttpOnly`, `SameSite=Lax` and `Secure` on HTTPS (see `config.example.php`).
- Cron endpoints require a secret key (`CRON_SECRET_KEY`) when called over the web.
- Access is role-based (admin / manager / staff).

What you must do:
- `config.php`, `.env`, database dumps and uploads are excluded by `.gitignore`. Secrets live only in `config.php` on the server; this repository has no credentials, keys, webhooks or default passwords.
- Create the first admin with a strong password, and keep `display_errors` off in production.
- Serve the site over HTTPS only.

Known limitations (good next steps): no CSRF tokens on forms and no login-attempt limiting.

Found a vulnerability? See [SECURITY.md](SECURITY.md).

## Author

**Ramah Rinaldi Ruslan** — Computer Engineering, Telkom University
Portfolio: https://ramahrinaldi.id · GitHub: [@rama907](https://github.com/rama907)

---

### Bahasa Indonesia

Sistem manajemen karyawan dan operasional untuk usaha kuliner: absensi dan duty, pengajuan cuti / pengunduran diri / duty manual dengan persetujuan, penggajian dan slip gaji, peringatan, data penjualan dan stok, notifikasi Discord, serta hak akses berdasarkan peran. Salin `config.example.php` menjadi `config.php`, impor `database.schema.sql`, buat akun admin pertama, lalu jadwalkan skrip cron.

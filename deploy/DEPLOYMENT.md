# Scholarship Manager deployment

These instructions deploy the application for **testing with local authentication and log-only email**. University-specific SSO and production email are intentionally excluded.

## Server requirements

- Ubuntu/Debian
- Nginx
- PHP 8.3+ FPM with `pdo_mysql`, `mbstring`, `dom`, `zip`, and `fileinfo`
- MariaDB 10.11+
- Composer
- Git

Application path:

```
/var/www/educ-scholarships
```

## 1. Pull the application

```bash
cd /var/www/educ-scholarships
git fetch origin
git checkout main
git pull --ff-only origin main
composer install --no-dev --optimize-autoloader --no-interaction
```

## 2. Configure environment

Copy `.env.example` to `.env` and set at least:

```dotenv
APP_ENV=testing
APP_URL=https://YOUR-HOSTNAME
APP_TIMEZONE=America/Chicago

DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=educ_scholarships
DB_USERNAME=YOUR_DB_USER
DB_PASSWORD=YOUR_DB_PASSWORD

AUTH_DRIVER=local
SESSION_SECURE=true
SESSION_MAX_LIFETIME_SECONDS=28800

MAIL_DRIVER=log

BOOTSTRAP_ADMIN_NAME=Your Name
BOOTSTRAP_ADMIN_EMAIL=your-email@example.edu
BOOTSTRAP_ADMIN_PASSWORD=USE-A-LONG-TEST-PASSWORD

LOCAL_STORAGE_PATH=storage/files
```

With `MAIL_DRIVER=log`, notification jobs complete without sending external email. Rendered messages are written to:

```
storage/logs/mail.log
```

## 3. File permissions

```bash
sudo chown -R www-data:www-data storage
sudo find storage -type d -exec chmod 770 {} \;
sudo find storage -type f -exec chmod 660 {} \;
sudo chmod 640 .env
```

The application code itself can remain owned by the deployment user. Only `storage/` needs web-server write access.

## 4. Run database setup

```bash
php bin/console migrate
php bin/console seed:base
php bin/console seed:admin
```

For a testing environment, load fake workflow data:

```bash
APP_ENV=testing php bin/console seed:test
```

The test seed prints reviewer and recipient login credentials.

## 5. Configure Nginx

Use `deploy/nginx.conf.example` as the application server block and adjust:

- `server_name`
- PHP-FPM socket if your installed version differs

Then validate and reload:

```bash
sudo nginx -t
sudo systemctl reload nginx
```

TLS should terminate at Nginx before setting `SESSION_SECURE=true`.

## 6. Install mail worker timer

```bash
sudo cp deploy/educ-scholarships-mail.service /etc/systemd/system/
sudo cp deploy/educ-scholarships-mail.timer /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now educ-scholarships-mail.timer
sudo systemctl status educ-scholarships-mail.timer
```

To run queued mail manually:

```bash
php bin/console jobs:work
```

## 7. Verify

```bash
curl -fsS https://YOUR-HOSTNAME/healthz
```

Expected response:

```
ok
```

Then test in this order:

1. Sign in as the bootstrap administrator.
2. Confirm the current test cycle and fake scholarships.
3. Sign in as the fake program reviewer and submit a recommendation.
4. Review it as the administrator.
5. Verify enrollment or use seeded workflow records.
6. Approve an award.
7. Configure letter/email templates.
8. Queue a notification.
9. Run the mail worker.
10. Inspect `storage/logs/mail.log`.
11. Sign in as the fake recipient.
12. Submit a distribution request and thank-you letter.
13. Review/download the thank-you from the administrative side.
14. Generate cycle exports and review the cycle close dashboard.

## Updating after deployment

```bash
cd /var/www/educ-scholarships
git pull --ff-only origin main
composer install --no-dev --optimize-autoloader --no-interaction
php bin/console migrate
sudo systemctl reload php8.3-fpm
```

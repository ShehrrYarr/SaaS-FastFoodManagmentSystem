# Deploying SFMS

How to run SFMS on a server for real shops. For a development machine, follow the README instead.

**Never run `db:seed` on a production database.** The sample seeders create test logins with the password `password` and fake sales history.

---

## 1. What the server needs

- PHP **8.3+** with `pdo_mysql`, `mbstring`, `bcmath`, `intl`, `fileinfo`, `openssl`, `pcntl` (Linux, for Reverb), `curl`
- MySQL 8+ (or MariaDB 10.6+). Tables must be **InnoDB**; `config/database.php` forces it.
- A web server (Nginx or Apache) with HTTPS
- Composer 2, and Node 18+ to build the assets (on the server or in CI)
- A process manager to keep the live-updates server running: Supervisor or systemd on Linux, NSSM on Windows

Only three things run:

| Process | Command | Notes |
|---|---|---|
| Web app | PHP-FPM (or Apache mod_php) serving `public/` | |
| Live updates | `php artisan reverb:start` | Websocket server, port 8080 behind the web server |
| Queue worker | not needed | Live updates are sent at the end of each request; nothing is queued yet |

No cron or scheduler is needed yet.

## 2. Install

```bash
git clone https://github.com/ShehrrYarr/SaaS-FastFoodManagmentSystem.git sfms
cd sfms
composer install --no-dev --optimize-autoloader
npm ci
npm run production        # builds public/assets (2–3 minutes)
cp .env.example .env
php artisan key:generate
php artisan sfms:live-keys
```

Edit `.env` (see section 3), create the database, then:

```bash
php artisan migrate --force
php artisan storage:link
php artisan sfms:super-admin you@yourcompany.com --name="Your Name"
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

`sfms:super-admin` asks for a password (at least 8 characters). Run it again with the same email to reset that password. Then sign in at `https://your-domain/super-admin/login` and create the first shop; the shop wizard creates the shop owner's login.

The web server user needs write access to `storage/` and `bootstrap/cache/`.

## 3. `.env` for production

```dotenv
APP_NAME=SFMS
APP_ENV=production
APP_DEBUG=false
APP_URL=https://pos.example.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=sfms
DB_USERNAME=sfms
DB_PASSWORD=a-strong-password

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
SESSION_LIFETIME=720

BROADCAST_CONNECTION=reverb

# Filled in by `php artisan sfms:live-keys`
REVERB_APP_ID=
REVERB_APP_KEY=
REVERB_APP_SECRET=

# Where the app (PHP) reaches Reverb: on the same machine.
REVERB_HOST=127.0.0.1
REVERB_PORT=8080
REVERB_SCHEME=http

# Where browsers reach Reverb: your public host over HTTPS, proxied (section 4).
REVERB_CLIENT_HOST=pos.example.com
REVERB_CLIENT_PORT=443
REVERB_CLIENT_SCHEME=https
```

- Keep `APP_DEBUG=false`: error pages would otherwise show configuration.
- Each shop's time zone and currency are set per shop; the server time zone does not matter (dates are stored in UTC).
- After any `.env` change: `php artisan config:cache`, and restart Reverb if its settings changed.

## 4. Web server

### Nginx

```nginx
server {
    listen 443 ssl http2;
    server_name pos.example.com;
    root /var/www/sfms/public;
    index index.php;

    # ssl_certificate ... ; ssl_certificate_key ... ;

    client_max_body_size 8m;   # product photos and shop logos

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }

    # Live updates: browsers connect to wss://pos.example.com/app/..., forwarded to Reverb.
    location ~ ^/(app|apps)/ {
        proxy_http_version 1.1;
        proxy_set_header Host $http_host;
        proxy_set_header Scheme $scheme;
        proxy_set_header SERVER_PORT $server_port;
        proxy_set_header REMOTE_ADDR $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "Upgrade";
        proxy_read_timeout 120s;
        proxy_pass http://127.0.0.1:8080;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

`app` and `apps` are reserved, so no shop can take them as its prefix.

### Apache

Point the virtual host's `DocumentRoot` at `public/` with `AllowOverride All` (Laravel's `.htaccess` handles routing). For live updates, enable `mod_proxy`, `mod_proxy_http` and `mod_proxy_wstunnel`, and add:

```apache
ProxyPass        /app  ws://127.0.0.1:8080/app
ProxyPassReverse /app  ws://127.0.0.1:8080/app
ProxyPass        /apps http://127.0.0.1:8080/apps
ProxyPassReverse /apps http://127.0.0.1:8080/apps
```

## 5. Keep Reverb running

### Supervisor (Linux)

`/etc/supervisor/conf.d/sfms-reverb.conf`:

```ini
[program:sfms-reverb]
command=php /var/www/sfms/artisan reverb:start --host=127.0.0.1 --port=8080
directory=/var/www/sfms
user=www-data
autostart=true
autorestart=true
stopwaitsecs=10
stdout_logfile=/var/www/sfms/storage/logs/reverb.log
redirect_stderr=true
minfds=10000
```

```bash
sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl start sfms-reverb
```

### Windows (one PC in the shop, e.g. WAMP)

Serve `public/` through Apache as above, and install Reverb as a service with [NSSM](https://nssm.cc):

```powershell
nssm install SFMS-Reverb "C:\wamp64\bin\php\php8.3.14\php.exe" "artisan reverb:start --host=127.0.0.1 --port=8080"
```

```powershell
nssm set SFMS-Reverb AppDirectory "C:\sfms"
```

```powershell
nssm start SFMS-Reverb
```

Tills on the shop's network then open `http://<that-pc>/{shop}/pos`. In that setup set `REVERB_CLIENT_HOST` to the PC's address, `REVERB_CLIENT_PORT=8080` and `REVERB_CLIENT_SCHEME=http`, or proxy it as above.

### Check it works

Open the **Open Tabs** board: it shows **● Live** when connected. If it says *Every minute*, check that Reverb is running (`storage/logs/reverb.log`), the proxy forwards `/app`, and the `REVERB_CLIENT_*` values match how browsers reach the site. Sales are never affected: if Reverb is down, each missed message is logged in `storage/logs/laravel.log` and screens refresh on a timer.

## 6. Updating

```bash
php artisan down
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run production
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache
php artisan reverb:restart
php artisan up
```

`reverb:restart` asks the running server to exit; Supervisor or NSSM starts it again with the new code.

## 7. Backups

The database is the whole business record (orders, ledgers, stock). Back it up at least nightly and keep copies off the server:

```bash
mysqldump --single-transaction --routines --triggers -u sfms -p sfms | gzip > sfms-$(date +%F).sql.gz
```

Also back up `storage/app/public` (product photos, shop logos) and your `.env` (keep it private: it holds the app key and passwords).

## 8. Printing at the till

Kitchen tickets, receipts and day reports open the browser's print dialog. For printing without the dialog, start Chrome on each till with `--kiosk-printing` and set the 80 mm thermal printer as the default printer. Each device can switch kitchen tickets or receipts off from the printer button in the POS.

## 9. Checklist

- [ ] `APP_DEBUG=false`, HTTPS on, `SESSION_SECURE_COOKIE=true`
- [ ] No sample data: `migrate --force` only, Super Admin made with `sfms:super-admin`
- [ ] `storage:link` done; `storage/` and `bootstrap/cache/` writable
- [ ] Reverb running under Supervisor/NSSM; Open Tabs shows **● Live**
- [ ] Nightly database backup tested by restoring it once
- [ ] Each shop: Cash Drawer counted and the first business day opened

## 10. A public demo under a path (e.g. `http://your-ip/sfmg`)

Used for the live demo server, next to other apps on the same Apache.

- **Apache:** a separate `conf-available/sfmg.conf` (`a2enconf sfmg`) with `Alias /sfmg /var/www/<app>/public`, the Laravel rewrite rules with `RewriteBase /sfmg/`, and for live updates `ProxyPass /sfmg/app http://127.0.0.1:8080/app upgrade=websocket` (needs `a2enmod proxy_http`). No other site's config is touched.
- **`.env`:** `APP_URL=http://your-ip/sfmg`, `ASSET_URL=/sfmg`, `SESSION_PATH=/sfmg` and a unique `SESSION_COOKIE` (other apps on the same host must not share the cookie), `REVERB_CLIENT_HOST=your-ip`, `REVERB_CLIENT_PORT=80`, `REVERB_CLIENT_SCHEME=http`, `REVERB_CLIENT_PATH=/sfmg`, and `SFMS_DEMO_SHOP=blg`.
- **Data:** `php artisan sfms:demo-reset --force` (instead of `migrate`) builds the demo, and a cron entry runs it nightly as the web user. Create your own Super Admin with `php artisan sfms:super-admin`; resets keep it.
- **Reverb:** a systemd unit running `php artisan reverb:start --host=127.0.0.1 --port=8080` as the web user.

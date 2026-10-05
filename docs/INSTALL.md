# MiniDash — Installation Guide

## Requirements

- **PHP 8.1+** with extensions: `pdo_sqlite`, `curl`, `sodium` (sodium is built into PHP in most distributions - check with `php -m`)
- **Web server**: nginx, Apache, or Synology Web Station
- **UniFi Controller** with API key (UniFi OS 3.x+ / Network 8.x+)
- **Background jobs** run every minute - built into the Docker image, set up with cron for other installs (see [Background jobs](#background-jobs-required-without-docker))

### Getting a UniFi API Key

1. Log into your UniFi Controller
2. Go to **Settings > Admins & Users > API Keys**
3. Click **Create API Key**
4. Choose permissions: **Read-Only** is enough for monitoring. Toggling access-control objects and firewall rules from MiniDash needs a key with write access
5. Copy the key — you'll need it during setup

---

## Option 1: Docker (recommended)

The fastest way to get MiniDash running. Works on any Docker host (Linux, Synology, QNAP, Windows, Mac).

### Step 1: Clone the repository

```bash
git clone https://github.com/lm-networks/MiniDash.git
cd MiniDash
```

### Step 2: Build and start

```bash
docker compose up -d
```

(Older Docker installations use `docker-compose up -d` instead.)

### Step 3: Setup Wizard

Open in browser:

```
http://your-server-ip:8080
```

The **Setup Wizard** will appear automatically on first run. Fill in:

- **Controller URL** — your UniFi Controller address (e.g. `https://192.168.1.1`)
- **API Key** — from UniFi Controller (see above)
- **Site ID** — usually `default`
- **Admin username & password** — your login credentials for MiniDash
- **Name & email** — displayed in your profile

Click **Save & Continue** — done!

The configuration is saved in the `data` volume, so it survives container rebuilds and updates. The container also runs the background jobs (alerts, daily report, WAN and client statistics) by itself - no cron needed.

To change the port, create a `.env` file next to `docker-compose.yml` before starting:

```bash
echo "MINIDASH_PORT=3000" > .env
docker compose up -d
```

**Optional: skip the Setup Wizard.** Put your settings in the same `.env` file before the first start. `UNIFI_API_KEY` and `ADMIN_PASSWORD` (at least 6 characters) are required; they are used only on the first start:

```bash
UNIFI_CONTROLLER_URL=https://192.168.1.1
UNIFI_API_KEY=your-api-key
ADMIN_USERNAME=admin
ADMIN_PASSWORD=choose-a-strong-password
```

### Updating

```bash
cd MiniDash
git pull
docker compose up -d --build
```

Configuration, data and logs are stored in Docker volumes and persist across updates.

> **Updating from a version older than 2.8.1:** older versions kept the configuration inside the container, so it is lost when the container is rebuilt. After this one update the Setup Wizard appears again - enter your settings once; from then on they are kept in the `data` volume.

---

## Option 2: Synology NAS (Container Manager — GUI)

The easiest way to run MiniDash on a Synology NAS with DSM 7.2+.

### Step 1: Upload project to NAS

Copy the MiniDash folder to your Synology, e.g. via File Station or SCP:

```
/volume1/docker/minidash/
```

Make sure the folder contains `docker-compose.yml`, `Dockerfile`, and all project files.

### Step 2: Create project in Container Manager

1. Open **Container Manager** on your Synology
2. Go to **Project** → **Create**
3. Set a project name, e.g. `minidash`
4. Set the path to `/volume1/docker/minidash`
5. Container Manager will detect `docker-compose.yml` automatically
6. Click **Next**, review the settings, then click **Done**

The container will build and start automatically.

### Step 3: Setup Wizard

Open in browser:

```
http://YOUR-NAS-IP:8080
```

The **Setup Wizard** will guide you through the configuration — fill in your UniFi Controller URL, API key, and admin credentials.

### Updating

1. Upload the new version of MiniDash to the same folder
2. In Container Manager → **Project** → select `minidash`
3. Click **Action** → **Build** (Kompilacja)
4. The container restarts with updated code; data in volumes is preserved

### Notes

- Configuration, data (SQLite database, avatars) and logs are stored in Docker volumes - they survive container rebuilds
- Background jobs (alerts, daily report, WAN and client statistics) run inside the container - no Task Scheduler entry needed
- To change the port, create `.env` with `MINIDASH_PORT=3000` and rebuild
- Container includes `bash` and `mc` (Midnight Commander) for terminal access
- If you copy the project from a Windows PC, that's fine - the image fixes Windows line endings in the start script

---

## Option 3: Synology NAS (Web Station — manual)

### Step 1: Install PHP 8.2

1. Open **Package Center** on your Synology
2. Install **Web Station** (if not already)
3. Install **PHP 8.2** package
4. In Web Station, go to **Script Language Settings > PHP 8.2**
5. Enable extensions: `pdo_sqlite`, `curl`, `sodium`, `json`

### Step 2: Upload files

1. Download or clone MiniDash to your computer
2. Upload the files to a shared folder, e.g.: `/web/minidash/`
   - Use File Station or SSH/SCP
   - Do NOT upload `node_modules/`, `_old/`, `.git/`

### Step 3: Set permissions

```bash
chown -R http:http /volume1/web/minidash/data
chown -R http:http /volume1/web/minidash/logs
```

### Step 4: Configure Web Station

**Option A: Virtual Host (recommended)**

1. In Web Station, go to **Web Service Portal**
2. Create a new portal:
   - Type: **Name-based**
   - Hostname: e.g., `unifi.yourdomain.com`
   - Document root: `/volume1/web/minidash`
   - PHP version: **PHP 8.2**

**Option B: Reverse Proxy (HTTPS on your own hostname)**

DSM's reverse proxy matches on hostname and port only (no paths like `/minidash`), so give MiniDash its own hostname:

1. Create the portal from Option A on a free local port, e.g. `8081`
2. In **Control Panel > Login Portal > Advanced > Reverse Proxy**, create a rule:
   - Source: `HTTPS`, hostname `unifi.yourdomain.com`, port `443`
   - Destination: `HTTP`, `localhost`, port `8081`
3. Assign a certificate for `unifi.yourdomain.com` in **Control Panel > Security > Certificate**

> **Security check (required after install).** MiniDash ships a root `.htaccess` that blocks
> `.env` (UniFi API key, admin password), `.git/` and `data/.encryption_key`. Apache honours it only
> with `AllowOverride All`. Verify from outside your network - every line must print `403`:
>
> ```bash
> for p in .env .git/config data/.env data/.encryption_key data/config.json; do
>   curl -s -o /dev/null -w "$p %{http_code}\n" https://unifi.yourdomain.com/$p
> done
> ```

### Step 5: Setup Wizard

Navigate to your configured hostname or IP. The **Setup Wizard** will appear — fill in your configuration and you're ready to go.

### Step 6: Background jobs

Without them there are no alerts, no daily report and no WAN/transfer history. In **Control Panel > Task Scheduler**, create two **Scheduled Tasks > User-defined script**, user `root`, repeat **every minute**:

```bash
# Task 1: alerts and daily report
/usr/local/bin/php82 /volume1/web/minidash/cron_triggers.php >/dev/null 2>&1

# Task 2: WAN and client statistics (offset by 30 s so the two jobs don't hit the database at once)
sleep 30; /usr/local/bin/php82 /volume1/web/minidash/update_wan.php >/dev/null 2>&1
```

---

## Option 4: Any Linux Server (nginx + PHP-FPM)

### Step 1: Install dependencies

**Debian 12+ / Ubuntu 22.04+:**
```bash
apt update
apt install php-fpm php-sqlite3 php-curl nginx git
php -m | grep -E 'pdo_sqlite|curl|sodium'   # all three must be listed
```

This installs your distribution's PHP (8.1 or newer); `sodium` is built in. Note the PHP version (`php -v`) - you need it for the PHP-FPM socket path in Step 3.

**RHEL/Rocky/Alma 9:**
```bash
dnf module enable php:8.2   # the default PHP on RHEL 9 is 8.0, MiniDash needs 8.1+
dnf install php-fpm php-pdo php-sodium nginx git
php -m | grep -E 'pdo_sqlite|curl|sodium'   # all three must be listed
```

### Step 2: Clone

```bash
cd /var/www
git clone https://github.com/lm-networks/MiniDash.git minidash
cd minidash

chown -R www-data:www-data data/ logs/
```

### Step 3: Configure nginx

Create `/etc/nginx/sites-available/minidash`:

```nginx
server {
    listen 80;
    server_name unifi.yourdomain.com;
    root /var/www/minidash;
    index index.php;

    # Gzip compression
    gzip on;
    gzip_types text/plain text/css application/json application/javascript text/xml image/svg+xml;
    gzip_min_length 256;

    # Security headers
    add_header X-Content-Type-Options nosniff;
    add_header X-Frame-Options SAMEORIGIN;
    add_header X-XSS-Protection "1; mode=block";

    # Cache static assets
    location ~* \.(css|js|png|jpg|jpeg|gif|ico|svg|woff2|woff)$ {
        expires 7d;
        add_header Cache-Control "public, immutable";
    }

    # Block access to sensitive files
    location ~ /\.env { deny all; }
    location ~ /data/ { deny all; }
    location ~ /logs/ { deny all; }
    location ~ /tests/ { deny all; }
    location ~ /docs/ { deny all; }
    location ~ /migrations/ { deny all; }
    location ~ /includes/ { deny all; }
    location ~ /docker/ { deny all; }
    location ~ /node_modules/ { deny all; }
    location ~ /\.git { deny all; }

    # Allow avatars
    location /data/avatars/ {
        alias /var/www/minidash/data/avatars/;
    }

    # PHP processing (adjust the socket to your PHP version, e.g. php8.3-fpm.sock;
    # RHEL-family: unix:/run/php-fpm/www.sock)
    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 30;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
}
```

Enable and restart:

```bash
ln -s /etc/nginx/sites-available/minidash /etc/nginx/sites-enabled/
nginx -t
systemctl restart nginx
```

> **Security check (required after install).** Verify from outside your network - every line must print `403`:
>
> ```bash
> for p in .env .git/config data/.env data/.encryption_key data/config.json; do
>   curl -s -o /dev/null -w "$p %{http_code}\n" https://unifi.yourdomain.com/$p
> done
> ```

### Step 4: Setup Wizard

Navigate to `http://unifi.yourdomain.com` — the Setup Wizard will guide you through the configuration.

### Step 5: Background jobs

Set up the two cron jobs from [Background jobs](#background-jobs-required-without-docker).

---

## Option 5: Apache

### Step 1: Install dependencies

```bash
apt install apache2 php libapache2-mod-php php-sqlite3 php-curl git
php -m | grep -E 'pdo_sqlite|curl|sodium'   # all three must be listed
```

### Step 2: Clone

```bash
cd /var/www
git clone https://github.com/lm-networks/MiniDash.git minidash
cd minidash

chown -R www-data:www-data data/ logs/
```

### Step 3: Apache VirtualHost

Create `/etc/apache2/sites-available/minidash.conf`:

```apache
<VirtualHost *:80>
    ServerName unifi.yourdomain.com
    DocumentRoot /var/www/minidash

    <Directory /var/www/minidash>
        AllowOverride All
        Require all granted
    </Directory>

    # Block sensitive directories
    <Directory /var/www/minidash/data>
        Require all denied
    </Directory>
    <Directory /var/www/minidash/logs>
        Require all denied
    </Directory>
    <Directory /var/www/minidash/tests>
        Require all denied
    </Directory>

    # Allow avatars
    <Directory /var/www/minidash/data/avatars>
        Require all granted
    </Directory>
</VirtualHost>
```

> **Security check (required after install).** MiniDash ships a root `.htaccess` that blocks
> `.env` (UniFi API key, admin password), `.git/` and `data/.encryption_key`. Apache honours it only
> with `AllowOverride All`. Verify from outside your network - every line must print `403`:
>
> ```bash
> for p in .env .git/config data/.env data/.encryption_key data/config.json; do
>   curl -s -o /dev/null -w "$p %{http_code}\n" https://unifi.yourdomain.com/$p
> done
> ```

Enable and restart:

```bash
a2ensite minidash
systemctl restart apache2
```

### Step 4: Setup Wizard

Navigate to your server address — the Setup Wizard will appear on first visit.

### Step 5: Background jobs

Set up the two cron jobs from [Background jobs](#background-jobs-required-without-docker).

---

## Background jobs (required without Docker)

MiniDash needs two jobs running every minute. Without them there are no alerts, no daily report and no WAN/transfer history. The Docker image runs them by itself; on Synology Web Station use Task Scheduler (Option 3, Step 6). On Linux, add them to the web server user's crontab (`crontab -u www-data -e`):

```cron
# Alerts and daily report
* * * * * php /var/www/minidash/cron_triggers.php >/dev/null 2>&1
# WAN and client statistics, offset by 30 s so the two jobs don't hit the database at once
* * * * * sleep 30; php /var/www/minidash/update_wan.php >/dev/null 2>&1
```

Run them as the web server user, not root - files they create in `data/` must stay writable for PHP. Errors go to `logs/php_errors.log`.

---

## Post-Installation

### Configure notifications

After login, click your avatar (top right) > click the bell icon settings gear. Configure Telegram, Discord, or other channels.

### Configure triggers

In the notification settings modal, scroll to the alert triggers to enable:
- Offline tolerance (anti-flapping) - ignore short disconnects such as Wi-Fi roaming
- Traffic spike alerts
- New device alerts
- IPS/IDS blocked attack alerts
- High latency alerts
- WAN link alerts (link down / back up, failover)
- Daily report
- VPN connection alerts

Triggers need the [background jobs](#background-jobs-required-without-docker) to be running.

### Data retention

Go to **System Settings** (gear icon in user menu) > **Data Retention** to configure how long data is kept (7-730 days per table).

### Changing language

Go to **Personal** (user menu) > **Regional Settings** > select PL or EN.

---

## Troubleshooting

### "Database error" on first visit

Make sure the `data/` directory is writable by the web server:
```bash
chown -R www-data:www-data data/
chmod 770 data/
```

### Cannot connect to UniFi Controller

1. Verify the controller URL is correct (include `https://`)
2. Check that the API key is valid and has read access
3. If using self-signed certificates (default on UniFi), this is handled automatically
4. Verify the controller is reachable from the MiniDash server (`200` = OK, `401` = wrong API key):
   ```bash
   curl -k -s -o /dev/null -w "%{http_code}\n" -H "X-API-KEY: your-api-key" \
     https://192.168.1.1/proxy/network/api/s/default/stat/device
   ```

### Blank page / PHP errors

Enable error logging:
```bash
# Check PHP error log
tail -f /var/www/minidash/logs/php_errors.log

# Or check system log
tail -f /var/log/php8.2-fpm.log
```

### Session issues

If you get logged out frequently, check:
- PHP session directory is writable: `ls -la /var/lib/php/sessions/`
- Session timeout setting in System Settings

---

## Security Recommendations

1. **Use HTTPS** — set up Let's Encrypt or a reverse proxy with SSL
2. **Restrict access** — use firewall rules to limit access to trusted IPs
3. **Keep updated** - `git pull && docker compose up -d --build`; MiniDash shows a banner when a new version is available

---

Created by Lukasz Misiura | [LM-Networks](https://www.lm-networks.pl) | [dev.lm-ads.com](https://dev.lm-ads.com)

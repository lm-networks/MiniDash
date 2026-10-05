#!/bin/sh

APP=/var/www/html
ENV_DATA=$APP/data/.env

# Create data directories if not exist
mkdir -p $APP/data/avatars $APP/logs
chown -R www-data:www-data $APP/data $APP/logs

# Konfiguracja trzymana jest w data/.env - data/ to wolumen, więc przetrwa przebudowę
# kontenera przy aktualizacji. Gdy jej brak, a w compose podano klucz API i hasło admina
# (min. 6 znaków), budujemy ją ze zmiennych środowiskowych; inaczej pokaże się kreator.
if [ ! -f "$ENV_DATA" ] && [ -n "$UNIFI_API_KEY" ] && [ ${#ADMIN_PASSWORD} -ge 6 ]; then
    cat > "$ENV_DATA" << EOF
UNIFI_CONTROLLER_URL=${UNIFI_CONTROLLER_URL:-https://192.168.1.1}
UNIFI_API_KEY=${UNIFI_API_KEY}
UNIFI_SITE=${UNIFI_SITE:-default}
ADMIN_USERNAME=${ADMIN_USERNAME:-admin}
ADMIN_PASSWORD=${ADMIN_PASSWORD}
ADMIN_FULL_NAME=${ADMIN_FULL_NAME:-Admin}
ADMIN_EMAIL=${ADMIN_EMAIL:-admin@example.com}
DEBUG=${DEBUG:-false}
EOF
    touch $APP/data/.installed
fi

# Bez zapisanej konfiguracji instalacja nie jest gotowa - usuń znacznik, żeby pokazał się
# kreator (inaczej panel ruszyłby bez klucza API i bez hasła admina).
if [ ! -f "$ENV_DATA" ]; then
    rm -f $APP/data/.installed
fi

# Run migrations
php $APP/db.php 2>/dev/null || true

# Fix ownership after migrations (db.php runs as root, creates files owned by root)
chown -R www-data:www-data $APP/data $APP/logs
chmod 600 "$ENV_DATA" 2>/dev/null

# Zadania w tle zamiast crona, jako www-data (te same uprawnienia co PHP-FPM):
# wyzwalacze alertów i raport dobowy co minutę oraz statystyki WAN/klientów co minutę,
# przesunięte o 30 s, żeby oba procesy nie trafiały w bazę jednocześnie.
run_every_minute() {
    while true; do
        su -s /bin/sh www-data -c "php $APP/$1" >/dev/null 2>&1
        sleep 60
    done
}
run_every_minute cron_triggers.php &
(sleep 30; run_every_minute update_wan.php) &

# Start PHP-FPM and Nginx
php-fpm -D
nginx -g "daemon off;"

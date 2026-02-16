#!/usr/bin/env sh
set -eu

mkdir -p /var/lib/printer-hub/jobs /var/log/printer-hub /run/cups /var/run/postgresql
chown -R www-data:www-data /var/lib/printer-hub /var/log/printer-hub /var/www/app /var/www/ui
chown -R postgres:postgres /var/lib/postgresql /var/run/postgresql

if [ -f /usr/local/share/printer-hub/cupsd.conf ]; then
  cp /usr/local/share/printer-hub/cupsd.conf /etc/cups/cupsd.conf
fi

if [ ! -s /var/lib/postgresql/15/main/PG_VERSION ]; then
  if pg_lsclusters 2>/dev/null | grep -q '^15\s\+main\s'; then
    pg_dropcluster --stop 15 main --force || true
  fi
  pg_createcluster --start-conf=manual 15 main
fi

if [ -f /etc/postgresql/15/main/postgresql.conf ]; then
  sed -ri "s/^#?listen_addresses\s*=.*/listen_addresses = '127.0.0.1'/" /etc/postgresql/15/main/postgresql.conf
fi

exec "$@"

#!/usr/bin/env sh
set -eu

DB_NAME="${DB_NAME:-printerhub}"
DB_USER="${DB_USER:-printerhub}"
DB_PASSWORD="${DB_PASSWORD:-printerhub}"

escape_sql() {
  printf "%s" "$1" | sed "s/'/''/g"
}

DB_NAME_ESC="$(escape_sql "$DB_NAME")"
DB_USER_ESC="$(escape_sql "$DB_USER")"

for _ in $(seq 1 45); do
  if pg_isready -h 127.0.0.1 -p 5432 -U postgres >/dev/null 2>&1; then
    break
  fi
  sleep 1
done

ROLE_EXISTS="$(psql -Atq --dbname=postgres -c "SELECT 1 FROM pg_roles WHERE rolname='${DB_USER_ESC}'")"
if [ "$ROLE_EXISTS" = "1" ]; then
  psql --dbname=postgres --set=db_user="$DB_USER" --set=db_password="$DB_PASSWORD" <<'SQL'
ALTER ROLE :"db_user" WITH LOGIN PASSWORD :'db_password';
SQL
else
  psql --dbname=postgres --set=db_user="$DB_USER" --set=db_password="$DB_PASSWORD" <<'SQL'
CREATE ROLE :"db_user" LOGIN PASSWORD :'db_password';
SQL
fi

DB_EXISTS="$(psql -Atq --dbname=postgres -c "SELECT 1 FROM pg_database WHERE datname='${DB_NAME_ESC}'")"
if [ "$DB_EXISTS" != "1" ]; then
  psql --dbname=postgres --set=db_name="$DB_NAME" --set=db_user="$DB_USER" <<'SQL'
CREATE DATABASE :"db_name" OWNER :"db_user";
SQL
fi

psql -v ON_ERROR_STOP=1 --dbname="${DB_NAME}" <<'SQL'
CREATE EXTENSION IF NOT EXISTS postgis;
SQL

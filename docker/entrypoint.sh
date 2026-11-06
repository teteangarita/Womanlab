#!/bin/sh
set -eu

export DB_PASSWORD="${DB_PASS:-${DB_PASSWORD:-}}"
if [ -n "${DB_SSL_CA_PEM:-}" ]; then
    DB_SSL_CA="$(mktemp /tmp/womanlab-db-ca.XXXXXX)"
    printf '%s\n' "$DB_SSL_CA_PEM" > "$DB_SSL_CA"
    chmod 644 "$DB_SSL_CA"
    export DB_SSL_CA
fi

exec apache2-foreground
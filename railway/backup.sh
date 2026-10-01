#!/bin/sh
set -eu
: "${DATABASE_URL:?Set DATABASE_URL securely}"
: "${BACKUP_FILE:?Set BACKUP_FILE to durable encrypted destination}"
pg_dump --dbname="$DATABASE_URL" --format=custom --no-owner --no-privileges --file="$BACKUP_FILE"
pg_restore --list "$BACKUP_FILE" >/dev/null

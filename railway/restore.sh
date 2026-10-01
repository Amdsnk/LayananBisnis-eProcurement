#!/bin/sh
set -eu
: "${RESTORE_DATABASE_URL:?Set isolated restore destination}"
: "${BACKUP_FILE:?Set backup path}"
# Never use the production database for a drill.
pg_restore --dbname="$RESTORE_DATABASE_URL" --no-owner --no-privileges --exit-on-error "$BACKUP_FILE"

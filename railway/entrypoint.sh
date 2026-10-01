#!/bin/sh
set -eu
cd /app
if [ "$#" -gt 0 ]; then exec "$@"; fi
php artisan procurement:check-config
php artisan config:cache
case "${SERVICE_ROLE:-web}" in
 web)
  envsubst '${PORT}' < /app/railway/nginx.conf.template > /tmp/nginx.conf
  exec supervisord -n -c /app/railway/supervisord.conf
  ;;
 worker) exec php artisan queue:work --sleep=3 --tries=3 --timeout=130 --max-time=3600 ;;
 scheduler) exec php artisan schedule:work ;;
 migrate) exec php artisan migrate --force ;;
 *) echo "Invalid SERVICE_ROLE" >&2; exit 1 ;;
esac

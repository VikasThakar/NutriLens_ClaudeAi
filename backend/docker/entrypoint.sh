#!/bin/sh
# Runtime setup for the NutriLens API container.
#
# Everything here needs either environment variables or the database, so none of
# it can be moved into the image build.
set -e

cd /app

: "${PORT:=8080}"
export PORT

echo "[entrypoint] rendering nginx config for port ${PORT}"
envsubst '${PORT}' < /etc/nginx/nginx.conf.template > /etc/nginx/nginx.conf

# The Railway volume mounts at /app/storage empty, hiding the directory tree
# that was baked into the image. Rebuilding it on every boot is what keeps the
# first session write, log line or compiled view from failing.
echo "[entrypoint] preparing storage tree"
mkdir -p \
  storage/app/public \
  storage/app/private \
  storage/framework/cache/data \
  storage/framework/sessions \
  storage/framework/views \
  storage/logs \
  bootstrap/cache

# public/storage -> storage/app/public. --force because the symlink survives in
# the image layer while its target was just replaced by the volume.
php artisan storage:link --force >/dev/null 2>&1 || true

echo "[entrypoint] caching configuration"
php artisan config:cache
php artisan route:cache || echo "[entrypoint] route cache skipped (routing still works, just uncached)"
php artisan view:cache  || echo "[entrypoint] view cache skipped"

if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
  echo "[entrypoint] running migrations"
  # A freshly provisioned Railway MySQL can still be accepting no connections
  # when the first deploy boots. Retrying beats crash-looping the service.
  attempt=0
  until php artisan migrate --force --no-interaction; do
    attempt=$((attempt + 1))
    if [ "$attempt" -ge 6 ]; then
      echo "[entrypoint] migrations failed after ${attempt} attempts — check DB_* variables"
      exit 1
    fi
    echo "[entrypoint] database not ready, retry ${attempt}/6 in 5s"
    sleep 5
  done
else
  echo "[entrypoint] RUN_MIGRATIONS=${RUN_MIGRATIONS} — skipping migrations"
fi

# The OpenAPI spec is written into storage/, which the volume just replaced, so
# it is regenerated per boot rather than at build time. Failing here costs the
# docs page, not the API.
php artisan l5-swagger:generate >/dev/null 2>&1 \
  || echo "[entrypoint] swagger generation skipped"

# Last, because every artisan command above ran as root and left root-owned
# files (the log, the compiled config) behind. php-fpm runs as www-data.
chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache

echo "[entrypoint] starting php-fpm + nginx"
exec supervisord -c /etc/supervisord.conf

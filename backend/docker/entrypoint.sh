#!/bin/sh
set -eu

# Render injects PORT at runtime (default 10000 per Render's docs — this
# default matters for `docker run` outside Render too, e.g. local
# validation). nginx config can't read env vars directly, so the template is
# rendered here. `envsubst '${PORT}'` explicitly limits substitution to just
# that variable — without the explicit list, envsubst would also mangle
# nginx's own $uri/$document_root/$query_string/etc. in the template.
export PORT="${PORT:-10000}"
envsubst '${PORT}' < /etc/nginx/nginx.conf.template > /etc/nginx/nginx.conf

# Render's platform-injected RENDER_EXTERNAL_URL (the service's own
# https://*.onrender.com URL) is used as a fallback so APP_URL is correct
# without requiring it to be set by hand — verified against Render's
# "Default Environment Variables" docs before relying on it here. An
# explicitly-set APP_URL (e.g. a future custom domain) always wins.
export APP_URL="${APP_URL:-${RENDER_EXTERNAL_URL:-}}"

# Database credentials. On Render, companion-ai-api and companion-ai-db both
# link the same env group, so the API receives the MySQL image's own variable
# names (MYSQL_DATABASE / MYSQL_USER / MYSQL_PASSWORD) — Render can't alias
# one service's variable into another under a different name — while Laravel
# reads DB_DATABASE / DB_USERNAME / DB_PASSWORD. Map them here. MYSQL_* wins
# when present so a stale hand-set DB_* value in the dashboard (e.g. an old
# DB_USERNAME=root) can never override the shared credentials. Only the
# application user is exposed to the API; MYSQL_ROOT_PASSWORD never is.
if [ -n "${MYSQL_DATABASE:-}" ]; then export DB_DATABASE="$MYSQL_DATABASE"; fi
if [ -n "${MYSQL_USER:-}" ]; then export DB_USERNAME="$MYSQL_USER"; fi
if [ -n "${MYSQL_PASSWORD:-}" ]; then export DB_PASSWORD="$MYSQL_PASSWORD"; fi

# Ownership only (not permissions) — the image already sets correct
# permissions at build time; this just re-asserts ownership in case a mounted
# volume overrode it, and is cheap/idempotent either way.
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# Migrations run here, before nginx/PHP-FPM start, so the app never serves
# traffic against an unmigrated database. This replaces a Render
# `preDeployCommand`, which Render does not support on the Free plan.
#
# Retries because the MySQL private service may still be booting (or waking)
# when this container starts. `migrate --force` is idempotent — with nothing
# pending it is a no-op — so re-running it on every start (including each
# wake-up from Free-plan spin-down) is safe. If the database never becomes
# reachable, exit non-zero rather than start an app that would 500 on every
# request: the failure is then visible in Render's logs and Render restarts
# the container. Single-instance only (Free plan cannot scale out), so no
# migration locking is needed.
attempts="${MIGRATE_MAX_ATTEMPTS:-30}"
i=1
until su -s /bin/sh www-data -c "php artisan migrate --force"; do
    if [ "$i" -ge "$attempts" ]; then
        echo "entrypoint: migrations failed after ${attempts} attempts — not starting the app." >&2
        exit 1
    fi
    echo "entrypoint: migration attempt ${i}/${attempts} failed (database not ready?) — retrying in 5s." >&2
    i=$((i + 1))
    sleep 5
done

exec supervisord -c /etc/supervisord.conf

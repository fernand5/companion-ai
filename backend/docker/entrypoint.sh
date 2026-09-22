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

# Ownership only (not permissions) — the image already sets correct
# permissions at build time; this just re-asserts ownership in case a mounted
# volume overrode it, and is cheap/idempotent either way.
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

exec supervisord -c /etc/supervisord.conf

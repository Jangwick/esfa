#!/bin/sh
# Container entry point on Vercel.
#
# FrankenPHP 1.12 can open its port before its PHP threads exist, and a request that arrives in
# that gap is never answered (it hangs until Vercel's 300-second limit). So FrankenPHP listens on an
# internal port (see Caddyfile), and the public port is opened only once PHP has answered a request.
(
    until wget -q -T 1 -O /dev/null http://127.0.0.1:9180/ready.php; do sleep 0.1; done
    exec socat TCP-LISTEN:"${PORT:-80}",fork,reuseaddr TCP:127.0.0.1:9180
) &

# A deploy that skips migrations leaves new tables/columns missing on the live database — run them before serving.
php /app/artisan migrate --force --no-interaction

exec frankenphp run --config /etc/caddy/Caddyfile
#!/bin/sh
# Entry point plus the ADR-0012 reload watcher. PHP writes cert/key and the
# generated HTTPS server block to the shared volumes; this script is the only
# thing that ever reloads nginx. PHP never calls docker or nginx.
set -eu

SSL_DIR="/etc/nginx/ssl"
CONF_DIR="/etc/nginx/virtusphere-conf.d"

mkdir -p "$SSL_DIR" "$CONF_DIR"

# Boot quarantine: nginx must always come up serving HTTP. If a generated conf
# breaks `nginx -t`, rename it aside (newest first, one per pass) and retest,
# so a bad write followed by a container restart cannot brick the stack.
while ! nginx -t >/dev/null 2>&1; do
    bad="$(ls -t "$CONF_DIR"/*.conf 2>/dev/null | head -n 1 || true)"
    if [ -z "$bad" ]; then
        # Not our generated config: surface the real error and stop.
        nginx -t
        exit 1
    fi
    echo "init.sh: quarantining broken config $bad -> $bad.bad" >&2
    nginx -t 2>&1 | sed 's/^/init.sh: /' >&2 || true
    mv "$bad" "$bad.bad"
done

# Fingerprint of everything a reload depends on. Missing files are fine (fresh
# install); the hash simply changes once they appear.
fingerprint() {
    cat "$CONF_DIR"/*.conf "$SSL_DIR"/server.crt "$SSL_DIR"/server.key 2>/dev/null | sha256sum
}

watcher() {
    last="$(fingerprint)"
    while :; do
        sleep 5
        current="$(fingerprint)"
        if [ "$current" = "$last" ]; then
            continue
        fi
        # Never reload into a broken config: on a failed test keep serving the
        # last good one and keep watching; the admin sees the reason in the
        # container log. Do not update $last, so a later fix retriggers.
        if nginx -t >/dev/null 2>&1; then
            nginx -s reload
            echo "init.sh: config change applied (nginx reloaded)" >&2
            last="$current"
        else
            echo "init.sh: config change REJECTED by nginx -t; still serving the previous config" >&2
            nginx -t 2>&1 | sed 's/^/init.sh: /' >&2 || true
        fi
    done
}

watcher &

exec nginx -g 'daemon off;'

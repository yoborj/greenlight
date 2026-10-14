#!/bin/bash
# HTTPS + HTTP/2 + gzip/brotli in front of the sandbox (like a real host's edge), on https://127.0.0.1:8443.
# Run serve.sh first. Lighthouse then needs: --chrome-flags="--ignore-certificate-errors".
# Note: switches the sandbox site URL to https://127.0.0.1:8443.
set -e
HERE="$(cd "$(dirname "$0")" && pwd)"; DIR="${1:-$HERE/.sandbox}"
cd "$DIR"
[ -f h2.key ] || openssl req -x509 -newkey rsa:2048 -nodes -keyout h2.key -out h2.crt -days 30 -subj "/CN=127.0.0.1" -addext "subjectAltName=IP:127.0.0.1" 2>/dev/null
grep -q HTTP_X_FORWARDED_PROTO wordpress/wp-config.php || sed -i '' "s#^\$table_prefix = 'wp_';#\$table_prefix = 'wp_';\nif ( ( \$_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '' ) === 'https' ) { \$_SERVER['HTTPS'] = 'on'; }#" wordpress/wp-config.php
./wp search-replace 'http://127.0.0.1:8899' 'https://127.0.0.1:8443' --all-tables --precise >/dev/null
exec node "$HERE/h2proxy.cjs" "$DIR"

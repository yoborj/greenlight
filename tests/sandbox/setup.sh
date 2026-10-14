#!/bin/bash
# Throwaway WordPress (SQLite + PHP's built-in server) with a deliberately slow test theme, for
# testing Greenlight without touching a real site. Usage:
#   PHP=/path/to/php tests/sandbox/setup.sh [dir]     then     tests/sandbox/serve.sh [dir]
# Needs: PHP 8 with pdo_sqlite + gd, curl, unzip, python3 with Pillow (for test images).
set -e
HERE="$(cd "$(dirname "$0")" && pwd)"; PLUGIN="$(cd "$HERE/../.." && pwd)"
DIR="${1:-$HERE/.sandbox}"; PHP="${PHP:-php}"; PORT="${PORT:-8899}"
mkdir -p "$DIR/dl" && cd "$DIR"
[ -f dl/wordpress.zip ] || curl -sSL -o dl/wordpress.zip https://wordpress.org/latest.zip
[ -f dl/sqlite.zip ] || curl -sSL -o dl/sqlite.zip https://downloads.wordpress.org/plugin/sqlite-database-integration.latest-stable.zip
[ -f dl/wp-cli.phar ] || curl -sSL -o dl/wp-cli.phar https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
unzip -q -o dl/wordpress.zip && unzip -q -o dl/sqlite.zip -d wordpress/wp-content/plugins
SQ="$DIR/wordpress/wp-content/plugins/sqlite-database-integration"
sed -e "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$SQ#g" -e "s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#g" "$SQ/db.copy" > wordpress/wp-content/db.php
mkdir -p wordpress/wp-content/database
cat > wordpress/wp-config.php <<'CFG'
<?php
define( 'DB_NAME', 'wp' ); define( 'DB_USER', '' ); define( 'DB_PASSWORD', '' ); define( 'DB_HOST', '' ); define( 'DB_CHARSET', 'utf8mb4' ); define( 'DB_COLLATE', '' );
define( 'DB_DIR', __DIR__ . '/wp-content/database/' ); define( 'DB_FILE', '.ht.sqlite' );
foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ) as $k ) { define( $k, 'sandbox-' . $k ); }
$table_prefix = 'wp_';
define( 'WP_DEBUG', true ); define( 'WP_DEBUG_LOG', __DIR__ . '/wp-content/debug.log' ); define( 'WP_DEBUG_DISPLAY', false );
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
require_once ABSPATH . 'wp-settings.php';
CFG
cp "$HERE/router.php" router.php
cat > wp <<WP
#!/bin/bash
exec "$PHP" -d memory_limit=512M "$DIR/dl/wp-cli.phar" --path="$DIR/wordpress" "\$@"
WP
chmod +x wp
./wp core install --url="http://127.0.0.1:$PORT" --title="Greenlight Sandbox" --admin_user=sandbox --admin_password="$(openssl rand -hex 12)" --admin_email=sandbox@example.com --skip-email
./wp rewrite structure '/%postname%/'
cp -R "$HERE/greenlight-test" wordpress/wp-content/themes/ && ./wp theme activate greenlight-test
python3 "$HERE/make-images.py" img
./wp option update sandbox_hero_url "$(./wp post get "$(./wp media import img/hero-original.jpg --porcelain)" --field=guid)"
for f in img/work-*.jpg; do ./wp media import "$f" >/dev/null; done
./wp option update sandbox_grid_urls "$(./wp post list --post_type=attachment --fields=guid --format=csv | grep work- | sort | python3 -c 'import sys,json;print(json.dumps([l.strip() for l in sys.stdin if l.strip()]))')" --format=json
ln -sfn "$PLUGIN" wordpress/wp-content/plugins/greenlight && ./wp plugin activate greenlight
echo "Sandbox ready in $DIR. Start it: PHP=$PHP $HERE/serve.sh $DIR"

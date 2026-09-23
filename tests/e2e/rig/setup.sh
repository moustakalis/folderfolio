#!/usr/bin/env bash
#
# A native WordPress for the e2e suite, where Playground will not boot.
#
# global-setup.ts boots WordPress Playground unless WP_BASE_URL is set, and
# `@wp-playground/cli` has never installed in the cloud container these
# sessions run in. This builds the same thing from parts that do: MariaDB, a
# WordPress core, `php -S` with four workers, and the staged plugin. First run
# 23 Sep 2026, WP 6.8.2, PHP 8.4, MariaDB 10.11: 87 tests in about 5 minutes.
#
# Usage (from the plugin root, after `yarn install` and `node tools/esbuild.mjs`):
#   WP_CORE=/path/to/wordpress bash tests/e2e/rig/setup.sh [site-dir]
#   WP_BASE_URL=http://127.0.0.1:9411 CHROMIUM_PATH=/opt/pw-browsers/chromium-1194/chrome-linux/chrome \
#     npx playwright test
#
# Needs: a running MariaDB/MySQL whose root can log in over the socket, php
# with mysqli and gd, a WordPress core directory to copy, and wp-cli (fetched
# into the site's parent directory if missing).
#
# Two rig-only pieces are copied in beside it:
# - e2e-login.php, a mu-plugin that makes every request the admin — what
#   Playground's `--login` does. One session token for the cookies it reads
#   and the ones it sends, or the first form on a page fails its nonce.
# - router.php, for `php -S`: WordPress's pretty permalinks and wp-json.
#
# The suite needs media for three rail specs; four PNGs are imported.

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
SITE="${1:-$HOME/wp-e2e}"
PORT="${WP_PORT:-9411}"
WP_CORE="${WP_CORE:?set WP_CORE to a WordPress core directory}"
WPCLI="$(dirname "$SITE")/wp-cli.phar"

if [ ! -f "$WPCLI" ]; then
  curl -sSL -o "$WPCLI" https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
fi
WP="php $WPCLI --allow-root --path=$SITE"

mysql -uroot -e "CREATE DATABASE IF NOT EXISTS wp_e2e;
  CREATE USER IF NOT EXISTS 'wp'@'localhost' IDENTIFIED BY 'wp';
  GRANT ALL ON wp_e2e.* TO 'wp'@'localhost'; FLUSH PRIVILEGES;"

rm -rf "$SITE"
cp -r "$WP_CORE" "$SITE"

$WP config create --dbname=wp_e2e --dbuser=wp --dbpass=wp --dbhost=127.0.0.1 --skip-check \
  --extra-php <<'PHP'
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', '/tmp/wp-e2e-debug.log');
define('WP_DEBUG_DISPLAY', false);
define('DISABLE_WP_CRON', true);
define('AUTOMATIC_UPDATER_DISABLED', true);
PHP
$WP db reset --yes
$WP core install --url="http://127.0.0.1:$PORT" --title=E2E --admin_user=admin \
  --admin_password=password --admin_email=admin@example.com --skip-email
# wp-cli guesses the site URL from the directory; say it.
$WP option update siteurl "http://127.0.0.1:$PORT"
$WP option update home "http://127.0.0.1:$PORT"
$WP rewrite structure '/%postname%/'

(cd "$ROOT" && bash bin/stage-plugin.sh "$ROOT/var/e2e-plugin/folderfolio")
ln -sfn "$ROOT/var/e2e-plugin/folderfolio" "$SITE/wp-content/plugins/folderfolio"
mkdir -p "$SITE/wp-content/mu-plugins"
cp "$ROOT/tests/e2e/rig/e2e-login.php" "$SITE/wp-content/mu-plugins/"
cp "$ROOT/tests/e2e/rig/router.php" "$SITE/"
$WP plugin activate folderfolio

php -r '
foreach (["#d63638", "#00a32a", "#2271b1", "#dba617"] as $i => $hex) {
    $im = imagecreatetruecolor(320, 240);
    [$r, $g, $b] = sscanf($hex, "#%02x%02x%02x");
    imagefill($im, 0, 0, imagecolorallocate($im, $r, $g, $b));
    imagepng($im, sys_get_temp_dir() . "/rig-$i.png");
}'
$WP media import "$(php -r 'echo sys_get_temp_dir();')"/rig-*.png --porcelain >/dev/null

# Detached, with every descriptor pointed away: a server that inherits this
# script's stdout keeps a caller's pipe (`setup.sh | tail`) open for ever.
cd "$SITE"
PHP_CLI_SERVER_WORKERS=4 setsid nohup php -d upload_max_filesize=64M -d post_max_size=64M \
  -d memory_limit=512M -S "127.0.0.1:$PORT" router.php > /tmp/php-e2e.log 2>&1 < /dev/null &

echo "WordPress on http://127.0.0.1:$PORT — rerun bin/stage-plugin.sh after a rebuild."

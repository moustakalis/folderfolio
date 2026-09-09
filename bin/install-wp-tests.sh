#!/usr/bin/env bash
set -euo pipefail

DB_NAME="${1:-wordpress_test}"
DB_USER="${2:-root}"
DB_PASS="${3:-root}"
DB_HOST="${4:-127.0.0.1}"
WP_VERSION="${5:-latest}"
SKIP_DB_CREATE="${6:-false}"

WP_TESTS_DIR="${WP_TESTS_DIR:-/tmp/wordpress-tests-lib}"
WP_CORE_DIR="${WP_CORE_DIR:-/tmp/wordpress}"

if [[ "$WP_VERSION" == "latest" || "$WP_VERSION" == "nightly" ]]; then
  WP_VERSION="$(curl -fsSL https://wordpress.org/latest-version.php?format=json | php -r '$version = json_decode(stream_get_contents(STDIN), true)["version"] ?? ""; if ($version === "") { exit(1); } echo $version;')"
fi

echo "Installing WordPress ${WP_VERSION} test suite"

rm -rf "$WP_CORE_DIR" "$WP_TESTS_DIR"
mkdir -p "$WP_CORE_DIR" "$WP_TESTS_DIR"

curl -fsSL "https://wordpress.org/wordpress-${WP_VERSION}.tar.gz" \
  | tar -xz --strip-components=1 -C "$WP_CORE_DIR"

curl -fsSL "https://github.com/WordPress/wordpress-develop/archive/refs/tags/${WP_VERSION}.tar.gz" \
  | tar -xz --strip-components=3 -C "$WP_TESTS_DIR" \
      "wordpress-develop-${WP_VERSION}/tests/phpunit/includes" \
      "wordpress-develop-${WP_VERSION}/tests/phpunit/data"

cp "$WP_TESTS_DIR/includes/wp-tests-config-sample.php" \
   "$WP_TESTS_DIR/wp-tests-config.php"

sed -i.bak \
  -e "s|dirname( __FILE__ ) . '/src/'|${WP_CORE_DIR}/|" \
  -e "s/youremptytestdbnamehere/${DB_NAME}/" \
  -e "s/yourusernamehere/${DB_USER}/" \
  -e "s/yourpasswordhere/${DB_PASS}/" \
  -e "s|localhost|${DB_HOST}|" \
  "$WP_TESTS_DIR/wp-tests-config.php"

rm -f "$WP_TESTS_DIR/wp-tests-config.php.bak"

if [[ "$SKIP_DB_CREATE" != "true" ]]; then
  mysqladmin create "$DB_NAME" \
    --user="$DB_USER" \
    --password="$DB_PASS" \
    --host="${DB_HOST%%:*}" \
    2>/dev/null || true
fi

test -f "$WP_TESTS_DIR/includes/functions.php"
test -f "$WP_TESTS_DIR/includes/bootstrap.php"

echo "WordPress test suite ready at ${WP_TESTS_DIR}"

#!/usr/bin/env bash
#
# Set up the WordPress test library the integration suite needs.
#
# The integration suite was, for months, the one check that ran nowhere but
# CI — which meant CI was the first thing to execute tests written days
# earlier, and when it finally did it found three real problems in one run.
# This is what makes it runnable before a push instead.
#
# It does what CI's setup action does: a WordPress to load, the test library
# from the same tag, and a wp-tests-config.php pointing both at a database.
#
# Usage:
#   bin/setup-wp-tests.sh [db-name] [db-user] [db-pass] [db-host] [wp-version]
#
# Then:
#   WP_TESTS_DIR=var/wp-tests-lib composer run test:integration
#
# Needs: svn (the test library is not in the wordpress.org tarball), curl,
# and a MySQL or MariaDB the credentials reach. The database is emptied by
# the suite on every run — point it at one you do not care about.

set -euo pipefail

DB_NAME="${1:-wordpress_test}"
DB_USER="${2:-wordpress}"
DB_PASS="${3:-wordpress}"
DB_HOST="${4:-127.0.0.1}"
WP_VERSION="${5:-latest}"

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET="${ROOT}/var"
CORE_DIR="${TARGET}/wordpress"
LIB_DIR="${TARGET}/wp-tests-lib"

for tool in svn curl; do
  if ! command -v "$tool" >/dev/null 2>&1; then
    echo "FATAL: ${tool} is required." >&2
    exit 1
  fi
done

mkdir -p "${TARGET}"

if [ ! -d "${CORE_DIR}" ]; then
  echo "Downloading WordPress (${WP_VERSION})…"
  if [ "${WP_VERSION}" = "latest" ]; then
    curl -sSL https://wordpress.org/latest.tar.gz -o "${TARGET}/wp.tgz"
  else
    curl -sSL "https://wordpress.org/wordpress-${WP_VERSION}.tar.gz" -o "${TARGET}/wp.tgz"
  fi
  tar xzf "${TARGET}/wp.tgz" -C "${TARGET}"
  rm "${TARGET}/wp.tgz"
fi

# The version the library must match is the one that was just unpacked, not
# the one that was asked for: "latest" is a moving target and a test library
# from a different release fails in ways that look like plugin bugs.
CORE_VERSION="$(sed -n "s/^\$wp_version = '\(.*\)';/\1/p" "${CORE_DIR}/wp-includes/version.php")"
SVN_TAG="https://develop.svn.wordpress.org/tags/${CORE_VERSION}"

if [ ! -d "${LIB_DIR}/includes" ]; then
  echo "Exporting the test library for ${CORE_VERSION}…"
  svn export -q --force "${SVN_TAG}/tests/phpunit/includes" "${LIB_DIR}/includes"
  svn export -q --force "${SVN_TAG}/tests/phpunit/data" "${LIB_DIR}/data"
  svn export -q --force "${SVN_TAG}/wp-tests-config-sample.php" "${TARGET}/wp-tests-config-sample.php"
fi

# The polyfills are a hard requirement of the WP test suite and come with
# composer install; the constant saves the suite guessing where they are.
cat > "${LIB_DIR}/wp-tests-config.php" <<PHP
<?php
define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', '${ROOT}/vendor/yoast/phpunit-polyfills' );
define( 'ABSPATH', '${CORE_DIR}/' );
define( 'WP_DEFAULT_THEME', 'default' );
define( 'DB_NAME', '${DB_NAME}' );
define( 'DB_USER', '${DB_USER}' );
define( 'DB_PASSWORD', '${DB_PASS}' );
define( 'DB_HOST', '${DB_HOST}' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
\$table_prefix = 'wptests_';
define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Blog' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );
PHP

echo
echo "Ready. WordPress ${CORE_VERSION} in var/wordpress, library in var/wp-tests-lib."
echo "Run:  WP_TESTS_DIR=${LIB_DIR} composer run test:integration"

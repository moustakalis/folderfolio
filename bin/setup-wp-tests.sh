#!/usr/bin/env bash
#
# Set up the WordPress test library the integration suite needs.
#
# The integration suite was, for months, the one check that ran nowhere but
# CI — which meant CI was the first thing to execute tests written days
# earlier, and when it finally did it found three real problems in one run.
# This is what makes it runnable before a push instead, and it is what CI
# runs too, so the two cannot drift.
#
# **No Subversion.** The usual recipe for this (install-wp-tests.sh, and the
# action this replaced) exports the library from develop.svn.wordpress.org,
# and svn is no longer installed on GitHub's runner images — the failure is
# `spawn svn ENOENT`, after which the job carries on and fails later for a
# reason that looks unrelated. macOS has not shipped svn since Catalina
# either. The same files are in the wordpress-develop tarball, over HTTPS,
# which every machine can already do.
#
# Usage:
#   bin/setup-wp-tests.sh [db-name] [db-user] [db-pass] [db-host] [wp-version]
#
# Then:
#   WP_TESTS_DIR=var/wp-tests-lib composer run test:integration
#
# Needs curl and tar, and a MySQL or MariaDB the credentials reach. The
# database is emptied by the suite on every run — point it at one you do not
# care about.

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

for tool in curl tar; do
  if ! command -v "$tool" >/dev/null 2>&1; then
    echo "FATAL: ${tool} is required." >&2
    exit 1
  fi
done

mkdir -p "${TARGET}"

if [ ! -d "${CORE_DIR}" ]; then
  echo "Downloading WordPress (${WP_VERSION})…"

  if [ "${WP_VERSION}" = "latest" ]; then
    CORE_URL="https://wordpress.org/latest.tar.gz"
  else
    CORE_URL="https://wordpress.org/wordpress-${WP_VERSION}.tar.gz"
  fi

  curl -fsSL "${CORE_URL}" | tar xz -C "${TARGET}"
fi

# The version the library must match is the one that was just unpacked, not
# the one that was asked for: "latest" is a moving target, and a library from
# a different release fails in ways that read as plugin bugs.
CORE_VERSION="$(sed -n "s/^\$wp_version = '\(.*\)';/\1/p" "${CORE_DIR}/wp-includes/version.php")"

if [ ! -f "${LIB_DIR}/includes/bootstrap.php" ]; then
  echo "Downloading the test library for ${CORE_VERSION}…"

  TESTS_URL="https://github.com/WordPress/wordpress-develop/archive/refs/tags/${CORE_VERSION}.tar.gz"
  WORK="$(mktemp -d)"
  trap 'rm -rf "${WORK}"' EXIT

  # The whole development repository is ~60MB and the library is a few of it,
  # but a tarball cannot be fetched in part. It is downloaded once and then
  # cached — in CI by actions/cache, locally by this directory existing.
  if ! curl -fsSL "${TESTS_URL}" \
    | tar xz -C "${WORK}" \
        "wordpress-develop-${CORE_VERSION}/tests/phpunit/includes" \
        "wordpress-develop-${CORE_VERSION}/tests/phpunit/data"; then
    echo "FATAL: could not fetch the test library for WordPress ${CORE_VERSION}." >&2
    echo "       Tried ${TESTS_URL}" >&2
    exit 1
  fi

  mkdir -p "${LIB_DIR}"
  rm -rf "${LIB_DIR}/includes" "${LIB_DIR}/data"
  mv "${WORK}/wordpress-develop-${CORE_VERSION}/tests/phpunit/includes" "${LIB_DIR}/includes"
  mv "${WORK}/wordpress-develop-${CORE_VERSION}/tests/phpunit/data" "${LIB_DIR}/data"
fi

# Written every run, never cached: the database and the paths are the part
# that changes between a laptop and a CI job.
#
# The polyfills are a hard requirement of the WP test suite and arrive with
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

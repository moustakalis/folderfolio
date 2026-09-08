#!/usr/bin/env bash

# WordPress test suite installer
# Usage: bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version] [skip-database-creation]

set -e

WP_TESTS_DIR=${WP_TESTS_DIR:-/tmp/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR:-/tmp/wordpress}

download() {
    if [ -x "$(command -v curl)" ]; then
        curl -s "$1" > "$2"
    elif [ -x "$(command -v wget)" ]; then
        wget -nv -O "$2" "$1"
    else
        echo "Neither curl nor wget found. Please install one."
        exit 1
    fi
}

if [ $# -lt 3 ]; then
    echo "Usage: $0 <db-name> <db-user> <db-pass> [db-host] [wp-version] [skip-database-creation]"
    exit 1
fi

DB_NAME=$1
DB_USER=$2
DB_PASS=$3
DB_HOST=${4-localhost}
WP_VERSION=${5-latest}
SKIP_DB_CREATE=${6-false}

# Download WordPress
if [ ! -d "$WP_CORE_DIR" ]; then
    mkdir -p "$WP_CORE_DIR"

    if [ "$WP_VERSION" == "latest" ]; then
        WP_ARCHIVE="https://wordpress.org/latest.tar.gz"
    else
        WP_ARCHIVE="https://wordpress.org/wordpress-$WP_VERSION.tar.gz"
    fi

    download "$WP_ARCHIVE" /tmp/wordpress.tar.gz
    tar --strip-components=1 -xzf /tmp/wordpress.tar.gz -C "$WP_CORE_DIR"
    rm /tmp/wordpress.tar.gz
fi

# Download WordPress test suite
if [ ! -d "$WP_TESTS_DIR" ]; then
    mkdir -p "$WP_TESTS_DIR"

    if [ "$WP_VERSION" == "latest" ]; then
        WP_TESTS_REPO="https://develop.svn.wordpress.org/trunk"
    else
        WP_TESTS_REPO="https://develop.svn.wordpress.org/tags/$WP_VERSION"
    fi

    svn export --quiet "$WP_TESTS_REPO/tests/phpunit/includes/" "$WP_TESTS_DIR/includes"
    svn export --quiet "$WP_TESTS_REPO/tests/phpunit/data/" "$WP_TESTS_DIR/data"
fi

# Create test database
if [ "$SKIP_DB_CREATE" == "false" ]; then
    mysql -u "$DB_USER" -p"$DB_PASS" -h "$DB_HOST" -e "CREATE DATABASE IF NOT EXISTS $DB_NAME;" 2>/dev/null || true
fi

# Create wp-tests-config.php
cat > "$WP_TESTS_DIR/wp-tests-config.php" <<EOF
<?php

// Database settings
define('DB_NAME', '$DB_NAME');
define('DB_USER', '$DB_USER');
define('DB_PASSWORD', '$DB_PASS');
define('DB_HOST', '$DB_HOST');
define('DB_CHARSET', 'utf8');
define('DB_COLLATE', '');

// Authentication keys (randomized)
define('AUTH_KEY', 'test-key-1');
define('SECURE_AUTH_KEY', 'test-key-2');
define('LOGGED_IN_KEY', 'test-key-3');
define('NONCE_KEY', 'test-key-4');
define('AUTH_SALT', 'test-salt-1');
define('SECURE_AUTH_SALT', 'test-salt-2');
define('LOGGED_IN_SALT', 'test-salt-3');
define('NONCE_SALT', 'test-salt-4');

define('ABSPATH', '$WP_CORE_DIR/');
EOF

echo "WordPress test suite installed successfully."

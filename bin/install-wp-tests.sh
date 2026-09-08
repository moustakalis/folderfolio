#!/usr/bin/env bash
set -e

WP_TESTS_DIR=${WP_TESTS_DIR:-/tmp/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR:-/tmp/wordpress}

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
        curl -sL "https://wordpress.org/latest.tar.gz" | tar xz --strip-components=1 -C "$WP_CORE_DIR"
    else
        curl -sL "https://wordpress.org/wordpress-$WP_VERSION.tar.gz" | tar xz --strip-components=1 -C "$WP_CORE_DIR"
    fi
fi

# Download test suite using curl instead of svn
if [ ! -d "$WP_TESTS_DIR/includes" ]; then
    mkdir -p "$WP_TESTS_DIR"
    
    # Get test suite version
    if [ "$WP_VERSION" == "latest" ]; then
        WP_TESTS_VERSION="trunk"
    else
        WP_TESTS_VERSION="tags/$WP_VERSION"
    fi
    
    # Download test files from GitHub mirror
    TESTS_URL="https://raw.githubusercontent.com/WordPress/wordpress-develop/$WP_TESTS_VERSION"
    
    mkdir -p "$WP_TESTS_DIR/includes"
    mkdir -p "$WP_TESTS_DIR/data"
    
    # Download essential test files
    curl -sL "$TESTS_URL/tests/phpunit/includes/bootstrap.php" -o "$WP_TESTS_DIR/includes/bootstrap.php"
    curl -sL "$TESTS_URL/tests/phpunit/includes/functions.php" -o "$WP_TESTS_DIR/includes/functions.php"
    curl -sL "$TESTS_URL/tests/phpunit/includes/testcase.php" -o "$WP_TESTS_DIR/includes/testcase.php"
    curl -sL "$TESTS_URL/tests/phpunit/includes/factory.php" -o "$WP_TESTS_DIR/includes/factory.php"
    curl -sL "$TESTS_URL/tests/phpunit/data/themedir1/default/style.css" -o "$WP_TESTS_DIR/data/themedir1/default/style.css" 2>/dev/null || true
fi

# Create database
if [ "$SKIP_DB_CREATE" == "false" ]; then
    mysql -u "$DB_USER" -p"$DB_PASS" -h "$DB_HOST" -e "CREATE DATABASE IF NOT EXISTS $DB_NAME;" 2>/dev/null || true
fi

# Create wp-tests-config.php
cat > "$WP_TESTS_DIR/wp-tests-config.php" <<EOF
<?php
define('DB_NAME', '$DB_NAME');
define('DB_USER', '$DB_USER');
define('DB_PASSWORD', '$DB_PASS');
define('DB_HOST', '$DB_HOST');
define('DB_CHARSET', 'utf8');
define('DB_COLLATE', '');
define('AUTH_KEY', 'test');
define('SECURE_AUTH_KEY', 'test');
define('LOGGED_IN_KEY', 'test');
define('NONCE_KEY', 'test');
define('AUTH_SALT', 'test');
define('SECURE_AUTH_SALT', 'test');
define('LOGGED_IN_SALT', 'test');
define('NONCE_SALT', 'test');
define('ABSPATH', '$WP_CORE_DIR/');
EOF

echo "WordPress test suite ready at $WP_TESTS_DIR"

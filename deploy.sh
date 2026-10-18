#!/usr/bin/env bash
# FILE: /deploy.sh
###############################################################################
# SplashEstate CRM - Deployment Script
#
# Checks the environment, prepares storage, backs up the database, runs the
# database migrations and the automated test suite.
#
# Usage: ./deploy.sh [--skip-tests] [--skip-migrations] [--no-backup] [--help]
#
# Fresh install: create the database from database.sql first
#   mysql -u USER -p < database.sql        (creates/uses `splashestate_crm`)
# then run ./deploy.sh - migrations are idempotent and are only recorded.
# Upgrades: ./deploy.sh applies pending migrations after taking a backup.
###############################################################################

set -euo pipefail
IFS=$'\n\t'

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT_DIR"

REQUIRED_PHP_VERSION_ID=70000   # PHP 7.0
REQUIRED_PHP_VERSION="7.0"
RUN_TESTS=1
RUN_MIGRATIONS=1
RUN_BACKUP=1

if [ -t 1 ]; then
    RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; BLUE='\033[0;34m'; NC='\033[0m'
else
    RED=''; GREEN=''; YELLOW=''; BLUE=''; NC=''
fi

print_success() { printf '%b✓%b %s\n' "$GREEN" "$NC" "$1"; }
print_error()   { printf '%b✗%b %s\n' "$RED" "$NC" "$1" >&2; }
print_info()    { printf '%bℹ%b %s\n' "$BLUE" "$NC" "$1"; }
print_warning() { printf '%b⚠%b %s\n' "$YELLOW" "$NC" "$1"; }
print_header()  { printf '\n%b========================================%b\n%b%s%b\n%b========================================%b\n\n' "$BLUE" "$NC" "$BLUE" "$1" "$NC" "$BLUE" "$NC"; }

usage() {
    sed -n '4,14p' "$0" | sed 's/^# \{0,1\}//'
}

for arg in "$@"; do
    case "$arg" in
        --skip-tests) RUN_TESTS=0 ;;
        --skip-migrations) RUN_MIGRATIONS=0 ;;
        --no-backup) RUN_BACKUP=0 ;;
        -h|--help) usage; exit 0 ;;
        *) print_error "Unknown option: $arg"; usage; exit 2 ;;
    esac
done

trap 'print_error "Deployment failed (line $LINENO). Nothing after this step was run."' ERR

check_php() {
    print_info "Checking PHP version..."
    if ! command -v php >/dev/null 2>&1; then
        print_error "PHP is not installed or not in PATH"
        exit 1
    fi
    local version_id version
    version_id="$(php -r 'echo PHP_VERSION_ID;')"
    version="$(php -r 'echo PHP_VERSION;')"
    if [ "$version_id" -lt "$REQUIRED_PHP_VERSION_ID" ]; then
        print_error "PHP $REQUIRED_PHP_VERSION or higher is required (found $version)"
        exit 1
    fi
    print_success "PHP $version detected"
}

check_extensions() {
    print_info "Checking required PHP extensions..."
    local missing
    missing="$(php -r 'foreach (array("pdo","pdo_mysql","mbstring","curl","json","openssl") as $e) { if (!extension_loaded($e)) echo $e, " "; }')"
    if [ -n "$missing" ]; then
        print_error "Missing PHP extensions: $missing"
        exit 1
    fi
    print_success "All required PHP extensions are installed"
}

check_env_file() {
    if [ ! -f .env ]; then
        cp .env.example .env
        chmod 600 .env
        print_warning ".env was missing and has been created from .env.example."
        print_warning "Configure database credentials, BASE_URL and APP_ENV in .env, then run ./deploy.sh again."
        exit 1
    fi
    print_success ".env file found"
}

# Print a config constant as PHP sees it (after .env parsing)
config_value() {
    php -r 'define("TEST_MODE", true); require "config/config.php"; $c = $argv[1]; echo defined($c) ? (is_bool(constant($c)) ? (constant($c) ? "true" : "false") : constant($c)) : "";' "$1"
}

validate_env() {
    print_info "Validating environment configuration..."
    local missing=()
    local var
    for var in DB_HOST DB_NAME DB_USER BASE_URL; do
        if [ -z "$(config_value "$var")" ]; then
            missing+=("$var")
        fi
    done
    if [ "${#missing[@]}" -ne 0 ]; then
        print_error "Missing configuration: ${missing[*]}"
        exit 1
    fi

    if [ "$(config_value APP_ENV)" = "production" ]; then
        if [ "$(config_value APP_DEBUG)" = "true" ]; then
            print_warning "APP_DEBUG is true in production - set APP_DEBUG=false"
        fi
        case "$(config_value BASE_URL)" in
            https://*) ;;
            *) print_warning "BASE_URL should use https:// in production" ;;
        esac
        if [ "$(config_value MAIL_DRIVER)" = "log" ]; then
            print_warning "MAIL_DRIVER=log in production - emails will not be delivered"
        fi
    fi
    print_success "Environment validation passed"
}

create_directories() {
    print_info "Preparing storage directories..."
    mkdir -p storage/uploads storage/logs storage/cache storage/backups database/migrations
    chmod 775 storage storage/uploads storage/logs storage/cache
    chmod 700 storage/backups
    print_success "Storage directories ready"
}

test_database() {
    print_info "Testing database connection..."
    local output
    if output="$(php -r '
        define("TEST_MODE", true);
        require "config/config.php";
        require "app/core/Database.php";
        try { Database::getInstance()->getConnection()->query("SELECT 1"); echo "OK"; }
        catch (Exception $e) { echo $e->getMessage(); exit(1); }
    ' 2>&1)"; then
        print_success "Database connection successful"
    else
        print_error "Database connection failed: $output"
        exit 1
    fi
}

backup_database() {
    if ! command -v mysqldump >/dev/null 2>&1; then
        print_warning "mysqldump not found - skipping database backup"
        return 0
    fi
    local file
    file="storage/backups/$(config_value DB_NAME)-$(date +%Y%m%d-%H%M%S).sql.gz"
    print_info "Backing up database to $file..."
    MYSQL_PWD="$(config_value DB_PASS)" mysqldump --single-transaction --routines --no-tablespaces \
        -h "$(config_value DB_HOST)" -u "$(config_value DB_USER)" "$(config_value DB_NAME)" | gzip > "$file"
    chmod 600 "$file"
    print_success "Backup written"
}

run_migrations() {
    print_info "Running database migrations..."
    php migrate.php migrate --no-color
    print_success "Migrations completed"
}

run_tests() {
    print_info "Running test suite (uses the separate splashestate_crm_test database)..."
    php tests/run_tests.php
    print_success "All tests passed"
}

clear_cache() {
    if [ -d storage/cache ]; then
        find storage/cache -mindepth 1 -delete
        print_success "Cache cleared"
    fi
}

main() {
    print_header "SplashEstate CRM Deployment"

    check_php
    check_extensions
    check_env_file
    validate_env
    create_directories
    test_database

    if [ "$RUN_MIGRATIONS" -eq 1 ]; then
        if [ "$RUN_BACKUP" -eq 1 ]; then
            backup_database
        fi
        run_migrations
    else
        print_warning "Skipping migrations (--skip-migrations)"
    fi

    if [ "$RUN_TESTS" -eq 1 ]; then
        run_tests
    else
        print_warning "Skipping tests (--skip-tests)"
    fi

    clear_cache

    print_header "Deployment Complete"
    print_info "Next steps:"
    echo "  1. Point the web server document root at $ROOT_DIR/public"
    echo "  2. Schedule cron.php (scheduled workflows, webhook retries)"
    echo "  3. Change or remove the demo accounts from database.sql before going live"
}

main

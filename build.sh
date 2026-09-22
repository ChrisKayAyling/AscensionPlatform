#!/usr/bin/env bash
#
# AscensionPlatform build & QA toolchain runner.
#
# Usage: ./build.sh <command> [command...]
#
#   install       Install composer dependencies (bootstraps composer.phar if
#                 the `composer` binary isn't on PATH).
#   db:init       (Re)initialise etc/db.db from db/schema.sql and seed a
#                 default admin user if one doesn't exist.
#   test          Run PHPUnit. Writes JUnit XML to build/reports/junit.xml.
#   lint          Run PHP_CodeSniffer (PSR-12). Writes a checkstyle report to
#                 build/reports/checkstyle.xml.
#   lint:fix      Auto-fix what PHP_CodeSniffer can (phpcbf).
#   docs          Generate API documentation with phpDocumentor into
#                 build/reports/docs/.
#   swagger       Generate an OpenAPI document from annotations in lib/ into
#                 build/reports/openapi.json.
#   reports       Refresh build/reports/summary.json, read by the admin QA
#                 Reports screen.
#   all           install, db:init, lint, test, docs, swagger, reports - in
#                 that order. Does not stop on lint/test failure so every
#                 report still gets produced; exits non-zero if any step failed.
#   version       Print the current platform version (latest git tag, or
#                 "0.0.0-dev" if untagged).
#
# Multiple commands may be given in one invocation, e.g.:
#   ./build.sh install test lint

set -uo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT_DIR"

REPORTS_DIR="$ROOT_DIR/build/reports"
COMPOSER_BIN="composer"
OVERALL_STATUS=0

log() { printf '\n\033[1m==> %s\033[0m\n' "$1"; }
fail() { printf '\033[31mFAILED: %s\033[0m\n' "$1"; OVERALL_STATUS=1; }

ensure_reports_dir() {
    mkdir -p "$REPORTS_DIR"
}

ensure_composer() {
    if command -v composer >/dev/null 2>&1; then
        COMPOSER_BIN="composer"
        return
    fi

    if [ -f "$ROOT_DIR/composer.phar" ]; then
        COMPOSER_BIN="php $ROOT_DIR/composer.phar"
        return
    fi

    log "composer not found on PATH - downloading composer.phar"
    php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
    php composer-setup.php --quiet
    rm -f composer-setup.php
    COMPOSER_BIN="php $ROOT_DIR/composer.phar"
}

cmd_install() {
    log "Installing composer dependencies"
    ensure_composer
    $COMPOSER_BIN install --no-interaction --prefer-dist || fail "composer install"
}

cmd_db_init() {
    log "Initialising database (etc/db.db)"
    php "$ROOT_DIR/db/setup.php" || fail "db:init"
}

cmd_test() {
    ensure_reports_dir
    log "Running PHPUnit"
    if [ -x vendor/bin/phpunit ]; then
        vendor/bin/phpunit --log-junit "$REPORTS_DIR/junit.xml" --testdox-text "$REPORTS_DIR/phpunit.txt" \
            || fail "phpunit"
    else
        fail "phpunit not installed - run './build.sh install' first"
    fi
}

cmd_lint() {
    ensure_reports_dir
    log "Running PHP_CodeSniffer (PSR-12)"
    if [ -x vendor/bin/phpcs ]; then
        vendor/bin/phpcs --standard="$ROOT_DIR/tools/phpcs.xml" --report=checkstyle --report-file="$REPORTS_DIR/checkstyle.xml" lib src
        # phpcs exits non-zero when it finds violations; that's not a build
        # failure by itself, the report is still produced either way.
        vendor/bin/phpcs --standard="$ROOT_DIR/tools/phpcs.xml" lib src || true
    else
        fail "phpcs not installed - run './build.sh install' first"
    fi
}

cmd_lint_fix() {
    log "Running phpcbf (auto-fix)"
    if [ -x vendor/bin/phpcbf ]; then
        vendor/bin/phpcbf --standard="$ROOT_DIR/tools/phpcs.xml" lib src
    else
        fail "phpcbf not installed - run './build.sh install' first"
    fi
}

cmd_docs() {
    ensure_reports_dir
    log "Generating phpDocumentor output"
    if [ -x vendor/bin/phpdoc ]; then
        vendor/bin/phpdoc run -d src -d lib -t "$REPORTS_DIR/docs" || fail "phpdoc"
    else
        fail "phpdoc not installed - run './build.sh install' first"
    fi
}

cmd_swagger() {
    ensure_reports_dir
    log "Generating OpenAPI document from lib/ annotations"
    if [ -x vendor/bin/openapi ]; then
        vendor/bin/openapi lib --output "$REPORTS_DIR/openapi.json" || fail "swagger-php"
    else
        fail "zircote/swagger-php not installed - run './build.sh install' first"
    fi
}

cmd_version() {
    git -C "$ROOT_DIR" describe --tags --always 2>/dev/null || echo "0.0.0-dev"
}

cmd_reports() {
    ensure_reports_dir
    log "Refreshing QA report summary"
    php -r '
        $reportsDir = $argv[1];
        $summary = [
            "generatedAt" => date(DATE_ATOM),
            "version" => trim(shell_exec("git describe --tags --always 2>/dev/null") ?: "0.0.0-dev"),
            "phpunit" => is_file("$reportsDir/junit.xml"),
            "checkstyle" => is_file("$reportsDir/checkstyle.xml"),
            "docs" => is_dir("$reportsDir/docs"),
            "openapi" => is_file("$reportsDir/openapi.json"),
        ];
        file_put_contents("$reportsDir/summary.json", json_encode($summary, JSON_PRETTY_PRINT));
        echo "Wrote $reportsDir/summary.json" . PHP_EOL;
    ' -- "$REPORTS_DIR"
}

cmd_all() {
    cmd_install
    cmd_db_init
    cmd_lint
    cmd_test
    cmd_docs
    cmd_swagger
    cmd_reports
}

if [ "$#" -eq 0 ]; then
    sed -n '2,29p' "$0"
    exit 0
fi

for arg in "$@"; do
    case "$arg" in
        install) cmd_install ;;
        db:init) cmd_db_init ;;
        test) cmd_test ;;
        lint) cmd_lint ;;
        lint:fix) cmd_lint_fix ;;
        docs) cmd_docs ;;
        swagger) cmd_swagger ;;
        reports) cmd_reports ;;
        all) cmd_all ;;
        version) cmd_version ;;
        *)
            echo "Unknown command: $arg" >&2
            exit 1
            ;;
    esac
done

exit $OVERALL_STATUS

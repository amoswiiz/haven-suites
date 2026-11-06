#!/usr/bin/env bash
set -Eeuo pipefail
umask 022

[[ "$EUID" -eq 0 ]] || { echo "Run this script as root on the Haven server."; exit 1; }
expected=${1:-}
[[ "$expected" =~ ^[a-f0-9]{7,40}$ ]] || { echo "Usage: bash deploy-haven.sh EXPECTED_COMMIT"; exit 1; }

exec 9>/run/lock/haven-prepare.lock
flock -n 9 || { echo "Another preparation or deployment is running."; exit 1; }

repo=/home/deploy/haven-repository
src="$repo/laravel"
live=/var/www/haven-suites

git_as_deploy() {
    runuser -u deploy -- git -C "$repo" "$@"
}

[[ "$(git_as_deploy branch --show-current)" == "migration/laravel-13" ]] \
    || { echo "Unexpected prepared branch; stopped."; exit 1; }
[[ "$(git_as_deploy rev-parse HEAD)" == "$(git_as_deploy rev-parse --verify "${expected}^{commit}")" ]] \
    || { echo "Unexpected prepared commit; stopped."; exit 1; }
[[ -z "$(git_as_deploy status --porcelain)" ]] \
    || { echo "Prepared checkout has changes; stopped."; exit 1; }
[[ -f "$src/app/Console/Commands/PreparePublicCatalogue.php" && -f "$src/public/build/manifest.json" && -f "$live/.env" ]] \
    || { echo "Application or built files missing. Run haven-prepare first."; exit 1; }
systemctl is-active --quiet php8.5-fpm \
    || { echo "Expected PHP-FPM service is not running; stopped."; exit 1; }

backup=$(mktemp -d /root/haven-deploy-XXXXXXXX)
mkdir -m 700 "$backup/files"
echo "File backup: $backup"
trap 'echo "Deployment stopped. Preserve the backups and review the error before bringing the site online." >&2' ERR

if [[ -f "$live/storage/logs/laravel.log" ]]; then
    chgrp www-data "$live/storage/logs/laravel.log"
    chmod g+w "$live/storage/logs/laravel.log"
fi

cd "$live"
runuser -u deploy -- php artisan down --retry=60
runuser -u deploy -- php <<'PHP'
<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = Illuminate\Support\Facades\DB::connection();
if ($db->getDriverName() !== 'sqlite' || ! $db->getSchemaBuilder()->hasTable('tenants')) {
    throw new RuntimeException('Expected the live central SQLite database; stopped before copying files.');
}
$directory = storage_path('app/private/deploy-backups');
if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
    throw new RuntimeException('Cannot create database backup directory.');
}
$path = $directory.'/central-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.sqlite';
$pdo = $db->getPdo();
$pdo->exec('VACUUM INTO '.$pdo->quote($path));
chmod($path, 0600);
echo "Database backup: $path\n";
PHP

rsync -rc --chown=deploy:www-data --backup --backup-dir="$backup/files" \
    --exclude='.env*' --exclude='/vendor/' --exclude='/node_modules/' \
    --exclude='/storage/' --exclude='/bootstrap/cache/' \
    --exclude='/public/storage' --exclude='/public/hot' \
    --exclude='*.sqlite*' --exclude='*.sql' --exclude='Pass' \
    "$src/" "$live/"

runuser -u deploy -- composer install \
    --no-dev --prefer-dist --optimize-autoloader --no-scripts --no-interaction
runuser -u deploy -- php artisan optimize:clear
runuser -u deploy -- php artisan package:discover --no-interaction
runuser -u deploy -- php artisan migrate --force --no-interaction
runuser -u deploy -- php artisan view:cache
systemctl reload php8.5-fpm
runuser -u deploy -- php artisan up
trap - ERR

echo "Deployed $(git_as_deploy rev-parse --short HEAD). File backup: $backup"
echo "Open https://app.havensuitesbnb.com/t/haven/public-listings to prepare and review apartment drafts."

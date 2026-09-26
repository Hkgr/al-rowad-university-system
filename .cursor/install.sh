#!/usr/bin/env bash
set -euo pipefail

# Idempotent bootstrap for the AlRowad University System monorepo.
# Layout: backend/ (Laravel 13, PHP 8.4, SQLite) and frontend/ (React 19 + Vite).
# Safe to run repeatedly and against a snapshot that already has the toolchain.

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# --- System toolchain: PHP 8.4 + Composer ----------------------------------
# Laravel 13 (via its locked Symfony 8 packages) requires PHP >= 8.4, which is
# newer than the Ubuntu 24.04 default (8.3), so it comes from the ondrej PPA.
if ! command -v php >/dev/null 2>&1 || ! php -r 'exit(version_compare(PHP_VERSION, "8.4", ">=") ? 0 : 1);'; then
  echo "==> Installing PHP 8.4 toolchain"
  sudo add-apt-repository -y ppa:ondrej/php
  sudo apt-get update -y
  sudo DEBIAN_FRONTEND=noninteractive apt-get install -y \
    php8.4-cli php8.4-common php8.4-mbstring php8.4-xml php8.4-curl \
    php8.4-sqlite3 php8.4-bcmath php8.4-intl php8.4-zip php8.4-gd
  sudo update-alternatives --set php /usr/bin/php8.4
fi

if ! command -v composer >/dev/null 2>&1; then
  echo "==> Installing Composer"
  php -r "copy('https://getcomposer.org/installer', '/tmp/composer-setup.php');"
  sudo php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
  rm -f /tmp/composer-setup.php
fi

# --- Backend (Laravel API) -------------------------------------------------
echo "==> Setting up backend (Laravel)"
cd "$REPO_ROOT/backend"
composer install --no-interaction --no-progress
[ -f .env ] || cp .env.example .env
grep -q '^APP_KEY=base64:' .env || php artisan key:generate --force
touch database/database.sqlite
php artisan migrate --force

# --- Frontend (React + Vite SPA) -------------------------------------------
# npm ci is intentionally avoided: the committed lockfile is out of sync with
# a few platform-specific transitive optional deps, which makes `npm ci` fail.
echo "==> Setting up frontend (React + Vite)"
cd "$REPO_ROOT/frontend"
[ -f .env ] || cp .env.example .env
npm install

echo "==> Install complete"

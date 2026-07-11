#!/usr/bin/env bash

set -Eeuo pipefail

# ==========================================================
# Node.js / nodenv environment
# ==========================================================

export NODENV_VERSION=24

if [ -d "$HOME/.nodenv/bin" ]; then
    export PATH="$HOME/.nodenv/bin:$HOME/.nodenv/shims:$PATH"
fi

if command -v nodenv >/dev/null 2>&1; then
    eval "$(nodenv init - bash)"
    nodenv shell "$NODENV_VERSION"
fi

echo "Node environment:"
echo "Node: $(node -v)"
echo "npm:  $(npm -v)"


# ==========================================================
# Al-Rowad University System - Plesk Deployment Script
# ==========================================================

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
FRONTEND_DIR="$PROJECT_ROOT/frontend"
BACKEND_DIR="$PROJECT_ROOT/backend"

# الموقع الفعلي الذي يخدمه الدومين
WEB_ROOT="$HOME/httpdocs"

echo "=========================================="
echo " Al-Rowad University Deployment Started"
echo "=========================================="

echo ""
echo "[1/6] Project root:"
echo "$PROJECT_ROOT"


# ----------------------------------------------------------
# 1. Frontend dependencies
# ----------------------------------------------------------

echo ""
echo "[2/6] Installing frontend dependencies..."

cd "$FRONTEND_DIR"

npm ci --no-audit --no-fund

echo "Frontend dependencies installed successfully with npm ci."


# ----------------------------------------------------------
# 2. Build React / Vite
# ----------------------------------------------------------

echo ""
echo "[3/6] Building frontend..."

npm run build

if [ ! -f "$FRONTEND_DIR/dist/index.html" ]; then
    echo "ERROR: frontend/dist/index.html was not generated."
    exit 1
fi

if [ ! -d "$FRONTEND_DIR/dist/assets" ]; then
    echo "ERROR: frontend/dist/assets was not generated."
    exit 1
fi


# ----------------------------------------------------------
# 3. Deploy frontend atomically
# ----------------------------------------------------------

echo ""
echo "[4/6] Publishing frontend to $WEB_ROOT ..."

TEMP_DEPLOY="$WEB_ROOT/.frontend-deploy-temp"

rm -rf "$TEMP_DEPLOY"
mkdir -p "$TEMP_DEPLOY"

cp -a "$FRONTEND_DIR/dist/." "$TEMP_DEPLOY/"

# حذف الـ assets القديمة فقط بعد نجاح الـ build
rm -rf "$WEB_ROOT/assets"

# نشر الملفات الجديدة
cp -a "$TEMP_DEPLOY/." "$WEB_ROOT/"

rm -rf "$TEMP_DEPLOY"

echo "Frontend published successfully."


# ----------------------------------------------------------
# 4. Laravel dependencies
# ----------------------------------------------------------

echo ""
echo "[5/6] Updating Laravel backend..."

cd "$BACKEND_DIR"

if command -v composer >/dev/null 2>&1; then
    composer install \
        --no-dev \
        --prefer-dist \
        --no-interaction \
        --optimize-autoloader
else
    echo "WARNING: Composer not found. Skipping composer install."
fi


# ----------------------------------------------------------
# 5. Clear Laravel cache
# ----------------------------------------------------------

echo ""
echo "[6/6] Clearing Laravel caches..."

php artisan optimize:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan cache:clear

echo ""
echo "=========================================="
echo " Deployment completed successfully"
echo "=========================================="

echo "Frontend:"
ls -lah "$WEB_ROOT/index.html"

echo ""
echo "Assets:"
ls -lah "$WEB_ROOT/assets" | head

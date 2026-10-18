#!/usr/bin/env bash

set -Eeuo pipefail

trap 'echo "ERROR: Deployment failed at line $LINENO." >&2' ERR

# ==========================================================
# Project paths
# ==========================================================

# Resolve the project directory from this script location.
# This prevents nodenv from trying to access /root when the
# script is launched manually by the root user.
PROJECT_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
cd "$PROJECT_ROOT"

FRONTEND_DIR="$PROJECT_ROOT/frontend"
BACKEND_DIR="$PROJECT_ROOT/backend"

# Plesk Document Root:
# /httpdocs/al-rowad-university-system/backend/public
WEB_ROOT="$BACKEND_DIR/public"

if [ ! -d "$FRONTEND_DIR" ]; then
    echo "ERROR: Frontend directory not found: $FRONTEND_DIR"
    exit 1
fi

if [ ! -d "$BACKEND_DIR" ]; then
    echo "ERROR: Backend directory not found: $BACKEND_DIR"
    exit 1
fi

if [ ! -d "$WEB_ROOT" ]; then
    echo "ERROR: Plesk web root not found: $WEB_ROOT"
    exit 1
fi

# ==========================================================
# Node.js / nodenv environment
# ==========================================================

export NODENV_VERSION="${NODENV_VERSION:-24}"

if [ -d "$HOME/.nodenv/bin" ]; then
    export PATH="$HOME/.nodenv/bin:$HOME/.nodenv/shims:$PATH"
fi

if command -v nodenv >/dev/null 2>&1; then
    eval "$(nodenv init - bash)"
    nodenv shell "$NODENV_VERSION"
fi

if ! command -v node >/dev/null 2>&1; then
    echo "ERROR: Node.js command not found."
    exit 1
fi

if ! command -v npm >/dev/null 2>&1; then
    echo "ERROR: npm command not found."
    exit 1
fi

echo "Node environment:"
echo "Node: $(node -v)"
echo "npm:  $(npm -v)"

# ==========================================================
# PHP / Composer environment
# ==========================================================

export PHPENV_ROOT="$HOME/.phpenv"

if [ -d "$PHPENV_ROOT/bin" ]; then
    export PATH="$PHPENV_ROOT/bin:$PHPENV_ROOT/shims:$PATH"
fi

# Prefer Plesk PHP 8.4.
if [ -x "/opt/plesk/php/8.4/bin/php" ]; then
    export PATH="/opt/plesk/php/8.4/bin:$PATH"
fi

if ! command -v php >/dev/null 2>&1; then
    echo "ERROR: PHP command not found."
    exit 1
fi

if ! command -v composer >/dev/null 2>&1; then
    echo "ERROR: Composer command not found."
    exit 1
fi

echo ""
echo "PHP environment:"
echo "PHP:      $(php -v | head -n 1)"
echo "PHP path: $(command -v php)"
echo "Composer: $(command -v composer)"

# ==========================================================
# Deployment
# ==========================================================

echo ""
echo "=========================================="
echo " Al-Rowad University Deployment Started"
echo "=========================================="

echo ""
echo "[1/6] Project paths:"
echo "Project:  $PROJECT_ROOT"
echo "Frontend: $FRONTEND_DIR"
echo "Backend:  $BACKEND_DIR"
echo "Web root: $WEB_ROOT"

# ----------------------------------------------------------
# 1. Install frontend dependencies
# ----------------------------------------------------------

echo ""
echo "[2/6] Installing frontend dependencies..."

cd "$FRONTEND_DIR"

npm ci --no-audit --no-fund

echo "Frontend dependencies installed successfully."

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

echo "Frontend build completed successfully."

# ----------------------------------------------------------
# 3. Publish frontend to Laravel public directory
# ----------------------------------------------------------

echo ""
echo "[4/6] Publishing frontend to $WEB_ROOT ..."

TEMP_DEPLOY="$WEB_ROOT/.frontend-deploy-temp.$$"

cleanup_temp_deploy() {
    if [ -d "$TEMP_DEPLOY" ]; then
        rm -rf "$TEMP_DEPLOY"
    fi
}

trap cleanup_temp_deploy EXIT

mkdir -p "$TEMP_DEPLOY"
cp -a "$FRONTEND_DIR/dist/." "$TEMP_DEPLOY/"

# Only replace generated frontend assets.
# Laravel's index.php and .htaccess remain untouched.
rm -rf "$WEB_ROOT/assets"
cp -a "$TEMP_DEPLOY/." "$WEB_ROOT/"

cleanup_temp_deploy
trap - EXIT

echo "Frontend published successfully."

# ----------------------------------------------------------
# 4. Install Laravel dependencies
# ----------------------------------------------------------

echo ""
echo "[5/6] Installing Laravel dependencies..."

cd "$BACKEND_DIR"

composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --optimize-autoloader

echo "Laravel dependencies installed successfully."

# ----------------------------------------------------------
# 5. Clear Laravel caches
# ----------------------------------------------------------

echo ""
echo "[6/6] Clearing Laravel caches..."

php artisan optimize:clear

echo "Laravel caches cleared successfully."

# ==========================================================
# Deployment verification
# ==========================================================

if [ ! -f "$WEB_ROOT/index.html" ]; then
    echo "ERROR: Published index.html was not found."
    exit 1
fi

if [ ! -d "$WEB_ROOT/assets" ]; then
    echo "ERROR: Published assets directory was not found."
    exit 1
fi

echo ""
echo "=========================================="
echo " Deployment completed successfully"
echo "=========================================="

echo ""
echo "Frontend:"
ls -lah "$WEB_ROOT/index.html"

echo ""
echo "Assets:"
ls -lah "$WEB_ROOT/assets" | head -n 15

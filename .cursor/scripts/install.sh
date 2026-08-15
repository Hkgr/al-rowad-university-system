#!/usr/bin/env bash
#
# Idempotent bootstrap for the Al Rowad University System monorepo on the default
# Cursor Cloud Agent image (Ubuntu 24.04).
#
# Runs after the source tree is checked out. Safe to run repeatedly: system
# packages are only installed when missing and app state is only imported/created
# when absent. With environment builds this runs once to create the baseline
# snapshot (including the seeded MariaDB data dir); per-boot service startup lives
# in start.sh.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
BACKEND_DIR="$REPO_ROOT/backend"
FRONTEND_DIR="$REPO_ROOT/frontend"
SCHEMA_SQL="$BACKEND_DIR/database/schema/al_rowad_university_db.sql"

DB_NAME="al_rowad_university_db"
DB_USER="laravel"
DB_PASS="laravel"

log() { printf '\n\033[1;32m[install]\033[0m %s\n' "$*"; }

# ---------------------------------------------------------------------------
# 1. System toolchains (installed only when missing)
#    - PHP 8.4: composer.lock resolves Symfony 8.x (needs PHP >= 8.4); also
#      matches deploy.sh's Plesk 8.4 preference. Ubuntu 24.04 only ships 8.3, so
#      we use the ondrej/php PPA.
#    - MariaDB: the domain schema lives in a SQL dump, not migrations.
#    - Composer + Node.js 24 (pinned in frontend/.node-version).
# ---------------------------------------------------------------------------
PHP_VERSION="8.4"

if ! command -v php >/dev/null 2>&1 || ! php -v 2>/dev/null | grep -q "^PHP ${PHP_VERSION}"; then
  log "Installing PHP ${PHP_VERSION} and extensions"
  sudo DEBIAN_FRONTEND=noninteractive apt-get update -y
  sudo DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
    software-properties-common ca-certificates curl gnupg
  sudo add-apt-repository -y ppa:ondrej/php
  sudo DEBIAN_FRONTEND=noninteractive apt-get update -y
  sudo DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
    "php${PHP_VERSION}-cli" "php${PHP_VERSION}-mysql" "php${PHP_VERSION}-mbstring" \
    "php${PHP_VERSION}-xml" "php${PHP_VERSION}-curl" "php${PHP_VERSION}-zip" \
    "php${PHP_VERSION}-bcmath" "php${PHP_VERSION}-gd" "php${PHP_VERSION}-sqlite3" \
    "php${PHP_VERSION}-intl"
  sudo update-alternatives --set php "/usr/bin/php${PHP_VERSION}"
fi

if ! command -v mysqld >/dev/null 2>&1 && ! command -v mariadbd >/dev/null 2>&1; then
  log "Installing MariaDB server + client"
  sudo DEBIAN_FRONTEND=noninteractive apt-get update -y
  sudo DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
    mariadb-server mariadb-client
fi

if ! command -v composer >/dev/null 2>&1; then
  log "Installing Composer"
  curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
  sudo php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
  rm -f /tmp/composer-setup.php
fi

if ! command -v node >/dev/null 2>&1 || [ "$(node -v | cut -d. -f1)" != "v24" ]; then
  log "Installing Node.js 24"
  curl -fsSL https://deb.nodesource.com/setup_24.x | sudo -E bash -
  sudo DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends nodejs
fi

# ---------------------------------------------------------------------------
# 2. MariaDB service + database/user
# ---------------------------------------------------------------------------
mariadb_running() { sudo mysqladmin ping >/dev/null 2>&1; }

start_mariadb() {
  if mariadb_running; then return 0; fi
  log "Starting MariaDB"
  sudo service mariadb start || sudo service mysql start || true
  for _ in $(seq 1 60); do
    mariadb_running && return 0
    sleep 1
  done
  echo "ERROR: MariaDB failed to start" >&2
  return 1
}

start_mariadb

log "Ensuring database and application user exist"
sudo mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
CREATE USER IF NOT EXISTS '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';
CREATE USER IF NOT EXISTS '${DB_USER}'@'%' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'127.0.0.1';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'%';
FLUSH PRIVILEGES;
SQL

# ---------------------------------------------------------------------------
# 3. Import the domain schema (first run only)
#    The phpMyAdmin dump adds primary keys via trailing ALTER TABLEs, but a few
#    newer tables declare inline FOREIGN KEYs that reference indexes not yet
#    created at that point (and some against the dump's signed-int PKs). Strip
#    inline FK constraints during import; the ORM enforces those relations.
# ---------------------------------------------------------------------------
schema_present="$(sudo mysql -N -e \
  "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_NAME}' AND table_name='account_statuses';")"
if [ "${schema_present}" = "0" ]; then
  log "Importing domain schema (first run)"
  awk '
    /^CREATE TABLE/ { inblock=1; n=0; delete buf }
    inblock==1 {
      if ($0 ~ /^\)/) {
        if (n>0) sub(/,[[:space:]]*$/, "", buf[n])
        for (i=1;i<=n;i++) print buf[i]
        print $0; inblock=0; next
      }
      if ($0 ~ /^[[:space:]]*CONSTRAINT.*FOREIGN KEY/) { next }
      buf[++n]=$0; next
    }
    { print }
  ' "$SCHEMA_SQL" \
    | { echo "SET FOREIGN_KEY_CHECKS=0;"; cat; echo "SET FOREIGN_KEY_CHECKS=1;"; } \
    | sudo mysql "${DB_NAME}"
else
  log "Domain schema already present; skipping import"
fi

# ---------------------------------------------------------------------------
# 4. Backend (Laravel / PHP)
# ---------------------------------------------------------------------------
cd "$BACKEND_DIR"

log "Installing backend Composer dependencies"
composer install --no-interaction --prefer-dist

if [ ! -f .env ]; then
  cp .env.example .env
fi

# Point Laravel at the local MariaDB instance.
sed -i \
  -e "s/^ *DB_CONNECTION=.*/DB_CONNECTION=mysql/" \
  -e "s/^ *DB_HOST=.*/DB_HOST=127.0.0.1/" \
  -e "s/^ *DB_PORT=.*/DB_PORT=3306/" \
  -e "s/^ *DB_DATABASE=.*/DB_DATABASE=${DB_NAME}/" \
  -e "s/^ *DB_USERNAME=.*/DB_USERNAME=${DB_USER}/" \
  -e "s/^ *DB_PASSWORD=.*/DB_PASSWORD=${DB_PASS}/" \
  .env

if ! grep -q '^APP_KEY=base64:' .env; then
  php artisan key:generate --force
fi

# Reconcile the migration ledger with the imported dump, then migrate.
#   - users / password_reset_tokens / user_access_scopes already exist in the dump.
#   - course_offering_instructors' migration declares unsigned FKs that cannot
#     reference the dump's signed PKs, so its table is created here (matching the
#     shape Laravel produces, minus the DB-level FKs) and the migration is marked
#     applied. This keeps `php artisan migrate` clean without editing app code.
php artisan migrate:install 2>/dev/null || true

mark_migrated() {
  sudo mysql "${DB_NAME}" -e \
    "INSERT INTO migrations (migration, batch) SELECT '$1', 1 FROM DUAL \
     WHERE NOT EXISTS (SELECT 1 FROM migrations WHERE migration='$1');"
}

mark_migrated "0001_01_01_000000_create_users_table"

sudo mysql "${DB_NAME}" <<'SQL'
CREATE TABLE IF NOT EXISTS course_offering_instructors (
  course_offering_instructor_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  course_offering_id INT UNSIGNED NOT NULL,
  faculty_member_id INT UNSIGNED NOT NULL,
  instructor_role VARCHAR(50) NOT NULL DEFAULT 'instructor',
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NULL DEFAULT NULL,
  updated_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (course_offering_instructor_id),
  UNIQUE KEY uq_course_offering_instructor (course_offering_id, faculty_member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL
mark_migrated "2026_07_14_090651_create_course_offering_instructors_table"

log "Running remaining migrations"
php artisan migrate --force

# Seed demo academic data + P0-1 authorization (idempotent update/first-or-create).
log "Seeding demo and authorization data"
php artisan db:seed --force

# Dev-only convenience: the dump's users ship a placeholder password hash, so give
# the seeded super-admin account a known credential for local sign-in.
#   admin@rowad.edu / Password123!
log "Setting local dev credential for admin@rowad.edu"
php artisan tinker --execute="
\$u = App\Models\User::where('email', 'admin@rowad.edu')->first();
if (\$u) { \$u->password_hash = Illuminate\Support\Facades\Hash::make('Password123!'); \$u->account_status_id = 1; \$u->save(); }
"

# ---------------------------------------------------------------------------
# 5. Frontend (React / Vite)
# ---------------------------------------------------------------------------
cd "$FRONTEND_DIR"

log "Installing frontend npm dependencies"
npm ci --no-audit --no-fund

if [ ! -f .env ]; then
  cp .env.example .env
fi
# Point the SPA login flow at the local API (CORS whitelists localhost:5173).
sed -i "s#^VITE_API_BASE_URL=.*#VITE_API_BASE_URL=http://127.0.0.1:8000/api#" .env

log "Install complete"

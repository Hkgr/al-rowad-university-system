#!/usr/bin/env bash
#
# Per-boot service reconciliation. Dependencies and the seeded database are
# prepared by install.sh; here we only ensure the MariaDB service is running so
# the backend/frontend terminals can connect. Safe to run on every boot.
set -euo pipefail

mariadb_running() { sudo mysqladmin ping >/dev/null 2>&1; }

if ! mariadb_running; then
  echo "[start] Starting MariaDB"
  sudo service mariadb start || sudo service mysql start || true
  for _ in $(seq 1 60); do
    mariadb_running && break
    sleep 1
  done
fi

if mariadb_running; then
  echo "[start] MariaDB is up"
else
  echo "[start] ERROR: MariaDB is not running" >&2
  exit 1
fi

#!/usr/bin/env sh
set -eu

HOST="${STATINGTON_HOST:-localhost}"
PORT="${STATINGTON_PORT:-8123}"

echo "Statington collector running at http://${HOST}:${PORT}"
echo "Dashboard: http://${HOST}:${PORT}"
php -S "${HOST}:${PORT}" server/router.php

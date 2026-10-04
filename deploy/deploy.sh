#!/bin/sh
# Run as michicast from ~/michicast.
set -eu
cd "$(dirname "$0")/.."

if [ ! -f deploy/.env ]; then
    echo "Missing deploy/.env. Copy deploy/.env.example and fill it in before deploying." >&2
    exit 1
fi

git pull --ff-only
docker compose build
docker compose up -d
docker compose exec -T app php artisan migrate --force

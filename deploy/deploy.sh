#!/bin/sh
# Run as the deploy user.
set -eu
cd "$(dirname "$0")/.."

if [ ! -f deploy/.env ]; then
    echo "Missing deploy/.env. Copy deploy/.env.example to deploy/.env and fill in secrets first." >&2
    exit 1
fi

git pull --ff-only
docker compose build
docker compose up -d
docker compose exec -T app php artisan migrate --force
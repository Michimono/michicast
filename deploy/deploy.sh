#!/bin/sh
# On the droplet, from the clone at ~/michicast.
set -eu
cd "$(dirname "$0")/.."

run_docker() {
    if [ "$(id -u)" -eq 0 ]; then
        docker "$@"
    else
        sudo docker "$@"
    fi
}

if [ ! -f deploy/.env ]; then
    echo "Missing deploy/.env. Copy deploy/.env.example and fill it in before deploying." >&2
    exit 1
fi

git pull --ff-only
run_docker compose build
run_docker compose up -d
run_docker compose exec -T app php artisan migrate --force

# Droplet

`compose.yaml` runs nginx, PHP 8.4, MySQL 8, a database queue worker, and `schedule:work`. MySQL data is the `mysql-data` volume. Code and Vite assets are baked into images, so the droplet does not compile them.

A 1 GB droplet should have about 1 GB of swap before the first boot: `fallocate -l 1G /swapfile && chmod 600 /swapfile && mkswap /swapfile && swapon /swapfile`, and that path in `/etc/fstab`. That is the only host step this file does not replace.

Do not run `docker compose build` on the droplet.

## Build

On a machine with enough RAM:

```sh
docker compose -f compose.yaml -f compose.build.yaml build
docker save michicast:local michicast-web:local | ssh root@DROPLET docker load
```

A registry works if you tag those two names, push, and `docker load` or `docker tag` them back to `michicast:local` and `michicast-web:local`. Compose will not pull: `pull_policy` is `never`.

## Server

Stop anything already bound to port 80. Copy `deploy/.env.example` to `deploy/.env` (gitignored). Set `APP_KEY` (`php artisan key:generate --show` on the build machine) and the passwords. `DB_PASSWORD` and `MYSQL_PASSWORD` stay in lockstep. The MySQL user is created only the first time the `mysql-data` volume is empty.

```sh
git pull
docker compose up -d
docker compose exec app php artisan migrate --force
```

Migrate is a deploy command. Container recreate does not delete `mysql-data` or `app-storage`.

The scheduler still runs `projects:dispatch` every minute. The queue worker only drains jobs that were actually queued (`QUEUE_CONNECTION=database`).

Redis is a comment in `compose.yaml`, not a service. Cache, session, and queue stay on MySQL.

TLS is not in this stack. The nginx CSP is the production header, `default-src 'self' http: https: data: blob`, with no `unsafe-inline`. Leave `BOOST_BROWSER_LOGS_WATCHER` false.

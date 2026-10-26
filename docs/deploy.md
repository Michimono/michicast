# Deploy

Host setup and app deploy are split. This repo only covers the **app** steps. Day-to-day operation (admins, moderation, dashboards, backups, outages) is in [operations.md](operations.md).

## 1. Host first-boot (Ansible)

Provision Docker, the deploy user, swap, UFW, and the app directory with:

https://github.com/Michimono/laravel-droplet-infra

On a fresh Ubuntu 26.04 droplet (as root):

```sh
sudo apt-get update
sudo apt-get install -y ansible
git clone https://github.com/Michimono/laravel-droplet-infra.git ~/laravel-droplet-infra
cd ~/laravel-droplet-infra
cp vars/secrets.yml.example vars/secrets.yml
# set deploy_user_password in vars/secrets.yml
ansible-playbook playbooks/provision.yml
```

That repo's defaults create user `michimono` and directory `~/michimono`. Ansible does **not** deploy this app, write `deploy/.env`, or run Compose. It does open TCP **80** and **443** in UFW so Caddy can serve HTTP (ACME) and HTTPS.

### Swap

A 1 GB droplet needs swap. The image build (npm and Composer stages) is the memory peak; a build that gets killed has run out of memory. Check that swap exists:

```sh
swapon --show
free -h
```

Keep at least 1 GB of swap (2 GB is safer if you build on the droplet). If the Ansible swap role did not create any:

```sh
sudo fallocate -l 2G /swapfile
sudo chmod 600 /swapfile
sudo mkswap /swapfile
sudo swapon /swapfile
echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab
```

## 2. DNS

Point an **A** (and optional **AAAA**) record for your public hostname at the droplet's IP. Propagation must reach the droplet before the first certificate issuance; Let's Encrypt validates over HTTP on port 80.

Use the same hostname as `APP_URL` / `DOMAIN` in `deploy/.env` (for example `APP_URL=https://cast.example.com` and `DOMAIN=cast.example.com`).

## 3. App deploy (this repo)

Log in as `michimono`, then clone once:

```sh
# Clone into ~/michimono if the directory is still empty
git clone https://github.com/Michimono/michicast.git ~/michimono
```

Every deploy, the first one included, is the same two steps:

```sh
cd ~/michimono
git pull --ff-only
bash deploy/deploy.sh
```

`deploy/deploy.sh` makes sure `deploy/.env` is production-ready, then deploys.

On the first run it copies `deploy/.env.example` to `deploy/.env` (mode `600`) and asks only for what it can't generate:

| Prompt | Key |
| --- | --- |
| Domain | `DOMAIN`; `APP_URL` is set to `https://<domain>` from it. |
| Let's Encrypt email | `ACME_EMAIL` |
| Platform admin email(s) | `PLATFORM_ADMIN_EMAILS` (defaults to the Let's Encrypt email). |
| Configure SMTP now? | Yes asks for `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD` and `MAIL_FROM_ADDRESS`. No keeps `MAIL_MAILER=log` and sets `DEPLOY_ALLOW_LOG_MAILER=true` so it isn't asked again. |

It generates every secret still empty or set to `change-me`: `APP_KEY`, `DB_PASSWORD` (copied to `MYSQL_PASSWORD`), `MYSQL_ROOT_PASSWORD` and `REDIS_PASSWORD`. It always enforces `APP_ENV=production`, `APP_DEBUG=false` and `SESSION_SECURE_COOKIE=true`.

**Back up `deploy/.env`.** `APP_KEY` decrypts destination credentials and 2FA secrets, and `MYSQL_*` is only read when the MySQL volume is first created. Once the `michicast_mysql-data` volume exists, the script refuses to generate a new `APP_KEY` or database password and tells you to restore the old one.

Later runs ask nothing when `deploy/.env` is complete. If a required value is missing they prompt for it, or, without a terminal, stop and list the missing keys. To edit a value, change it in `deploy/.env` and re-run.

The deploy itself runs `docker compose build --pull`, `docker compose up -d --remove-orphans --wait`, `php artisan migrate --force` in the `app` container, and `php artisan horizon:terminate` so Horizon restarts on the new code. It finishes by checking `https://<DOMAIN>/up`.

To only check or complete `deploy/.env` without deploying: `bash deploy/deploy.sh --env-only`.

### First certificate issuance

After DNS points at the droplet and `deploy/.env` has `DOMAIN` + `ACME_EMAIL`:

1. Run `bash deploy/deploy.sh` (or `docker compose up -d` if images are already built).
2. Watch Caddy obtain the cert: `docker compose logs -f caddy`.
3. Open `https://<DOMAIN>`; you should get a valid certificate. `http://<DOMAIN>` should redirect to HTTPS.
4. Confirm `Strict-Transport-Security` on an HTTPS response and that session cookies include `Secure`.

Renewal is automatic inside the Caddy container (certificates live in the `caddy-data` volume). No cron or certbot step is required on the host.

### First admin

1. Open `https://<DOMAIN>/users/create` and register with the email you put in `PLATFORM_ADMIN_EMAILS`. Registration is open by default.
2. Open `https://<DOMAIN>/admin` and confirm your password. The admin sidebar should list Overview, Users, Organizations, Activity, Audit, Settings, Horizon and Pulse.
3. If `/admin` answers 404, your email is not in `PLATFORM_ADMIN_EMAILS` or the app container predates the change; see [Promote or revoke an admin](operations.md#promote-or-revoke-an-admin).

Health check: `curl -fsS https://<DOMAIN>/up`.

## Compose stack

`compose.yaml` defines seven services. Only `caddy` publishes ports to the host.

| Service | What it does | Expected memory |
| --- | --- | --- |
| `caddy` | TLS termination and Let's Encrypt on 80/443, reverse proxy to `web`. | ~20-40 MB |
| `web` | nginx. Serves `public/` and passes PHP to `app`. Sets the CSP header (looser only on `/admin/horizon` and `/admin/pulse`). | ~5-15 MB |
| `app` | php-fpm, `pm = ondemand`, at most 3 children, `memory_limit = 128M`. Also where `php artisan` commands run. | ~20 MB idle, up to ~150 MB under load |
| `horizon` | `php artisan horizon`. Queue workers for `default` and `mail` on Redis, at most 2 processes (worker limit 128 MB, master 64 MB). | ~80-150 MB |
| `scheduler` | `php artisan schedule:work`. Runs `projects:dispatch` every minute plus the daily prunes. | ~30-60 MB, short spikes per tick |
| `mysql` | MySQL 8.0, `innodb-buffer-pool-size=64M`, performance schema off, 30 connections. Data in the `mysql-data` volume. | ~200-350 MB |
| `redis` | Redis 7, `maxmemory 64mb`, `noeviction`, AOF on. Holds the queue and Horizon metrics. Data in the `redis-data` volume. | under ~80 MB |

The memory figures are rough expectations from these limits, not guarantees; check the real numbers with `docker stats --no-stream`. Together they leave little headroom on 1 GB, which is why swap matters. There is no Pulse worker service (see [Pulse](operations.md#pulse)).

Volumes: `mysql-data`, `redis-data`, `app-storage` (logs, framework cache, shared by `app`, `horizon`, `scheduler` and read-only by `web`), `caddy-data`, `caddy-config`.

Useful commands, run from `~/michimono`:

```sh
docker compose ps
docker compose logs -f app horizon scheduler
docker compose exec app php artisan about
```

Application logs go to `storage/logs/laravel.log` in the `app-storage` volume: `docker compose exec app tail -n 100 storage/logs/laravel.log`.

Changing `deploy/.env` needs `docker compose up -d` to recreate the containers. `docker compose restart` does not reload the env file.

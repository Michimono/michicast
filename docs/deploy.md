# Deploy

Host setup and app deploy are split. This repo only covers the **app** steps.

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

## 2. DNS

Point an **A** (and optional **AAAA**) record for your public hostname at the droplet's IP. Propagation must reach the droplet before the first certificate issuance; Let's Encrypt validates over HTTP on port 80.

Use the same hostname as `APP_URL` / `DOMAIN` in `deploy/.env` (for example `APP_URL=https://cast.example.com` and `DOMAIN=cast.example.com`).

## 3. App deploy (this repo)

Log in as `michimono`, then:

```sh
# Clone into ~/michimono if the directory is still empty
git clone https://github.com/Michimono/michicast.git ~/michimono

cd ~/michimono
cp deploy/.env.example deploy/.env
# Fill secrets: APP_KEY, APP_URL, DOMAIN, ACME_EMAIL, DB_*/MYSQL_* passwords, REDIS_PASSWORD, etc.
# DOMAIN must match the host in APP_URL. ACME_EMAIL is the Let's Encrypt account contact.
# SESSION_SECURE_COOKIE=true is already set in the example (required behind TLS).
# Generate REDIS_PASSWORD (required by the redis Compose service), e.g.:
#   sed -i "s/^REDIS_PASSWORD=.*/REDIS_PASSWORD=$(openssl rand -base64 32)/" deploy/.env
bash ~/michimono/deploy/deploy.sh
```

`deploy/deploy.sh` pulls, builds Compose, starts services, runs migrations, and terminates Horizon so workers pick up new code.

Compose layout for TLS:

- **caddy** publishes host ports 80 and 443, obtains and renews Let's Encrypt certificates for `DOMAIN`, redirects HTTP to HTTPS (ACME challenge path excepted), and reverse-proxies to **web**.
- **web** (nginx) stays on the internal Compose network only and keeps the existing CSP header.
- MySQL and Redis stay unpublished on the host.

### First certificate issuance

After DNS points at the droplet and `deploy/.env` has `DOMAIN` + `ACME_EMAIL`:

1. Run `bash ~/michimono/deploy/deploy.sh` (or `docker compose up -d` if images are already built).
2. Watch Caddy obtain the cert: `docker compose logs -f caddy`.
3. Open `https://<DOMAIN>` â€” you should get a valid certificate. `http://<DOMAIN>` should redirect to HTTPS.
4. Confirm `Strict-Transport-Security` on an HTTPS response and that session cookies include `Secure`.

Renewal is automatic inside the Caddy container (certificates live in the `caddy-data` volume). No cron or certbot step is required on the host.

If the build is killed, it ran out of memory (the Ansible swap role is meant to help).

## Pulse

Pulse is mounted at /admin/pulse behind the platform-admin gate. Ingest uses the storage driver by default (no pulse:work process). Retention is 7 days.

There is no pulse Compose service: pulse:check would cost another ~30–50 MB RSS on the 1 GB droplet, so the Servers card is omitted from the dashboard. To enable it later, add a Compose service running php artisan pulse:check, set PULSE_SERVER_NAME, and restore ` <livewire:pulse.servers /> ` in esources/views/vendor/pulse/dashboard.blade.php.

Optional scale path under load: PULSE_INGEST_DRIVER=redis plus php artisan pulse:work (prefer PULSE_REDIS_CONNECTION on a dedicated Redis DB, not the queue connection).

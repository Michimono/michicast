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

That repo's defaults create user `michimono` and directory `~/michimono`. Ansible does **not** deploy this app, write `deploy/.env`, or run Compose.

## 2. App deploy (this repo)

Log in as `michimono`, then:

```sh
# Clone into ~/michimono if the directory is still empty
git clone https://github.com/Michimono/michicast.git ~/michimono

cd ~/michimono
cp deploy/.env.example deploy/.env
# Fill secrets: APP_KEY, APP_URL, DB_*/MYSQL_* passwords, etc.
bash ~/michimono/deploy/deploy.sh
```

`deploy/deploy.sh` pulls, builds Compose, starts services, and runs migrations.

If the build is killed, it ran out of memory (the Ansible swap role is meant to help).

HTTPS is not included yet (the infra playbook opens port 443 for future TLS only).
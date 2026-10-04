# Deploy

Ubuntu 24.04. Repo at `~/michicast`.

```sh
bash ~/michicast/deploy/setup-droplet.sh
```

Edit `~/michicast/deploy/.env` once (copy `deploy/.env.example`). Set `APP_KEY` and the passwords. `DB_PASSWORD` and `MYSQL_PASSWORD` must match.

```sh
bash ~/michicast/deploy/deploy.sh
```

If the build is killed, it ran out of memory.

HTTPS is not included yet.

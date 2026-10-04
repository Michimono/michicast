# Deploy

Ubuntu 26.04. As root:

```sh
bash ~/michicast/deploy/setup-droplet.sh
```

Answer the prompts (Ubuntu user, login password, MySQL user, MySQL password, database name). Then log in as that user:

```sh
bash ~/michicast/deploy/deploy.sh
```

If the build is killed, it ran out of memory.

HTTPS is not included yet.

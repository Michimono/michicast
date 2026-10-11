# Operations

Runbook for the droplet described in [deploy.md](deploy.md). Run every command as the deploy user from `~/michimono`:

```sh
cd ~/michimono
```

Commands that change `deploy/.env` only take effect after `docker compose up -d` recreates the containers. `docker compose restart` does not reload the env file.

## Promote or revoke an admin

There is no database flag and no Artisan promotion command. Platform admins are the comma-separated emails in `PLATFORM_ADMIN_EMAILS` (case-insensitive, trimmed). Empty or missing means nobody is an admin.

1. Edit `deploy/.env`:

   ```sh
   PLATFORM_ADMIN_EMAILS=you@example.com,colleague@example.com
   ```

2. Recreate the containers and check the value the app sees:

   ```sh
   docker compose up -d
   docker compose exec app php artisan config:show michicast.platform_admin_emails
   ```

To promote, add the email (the account can be registered before or after). To revoke, remove it; the person's next request to `/admin` answers 404. Platform admins cannot be banned from `/admin/users`, so revoke first if you need to ban one.

A banned user is never an admin, even when listed.

## Admin lockout

`/admin`, `/admin/horizon` and `/admin/pulse` answer **404** (not 403) to anyone who fails the admin check, so a lockout looks like a missing page.

| Symptom | Fix |
| --- | --- |
| 404 on `/admin` while signed in | Email not in `PLATFORM_ADMIN_EMAILS`, or the containers were not recreated. See above. |
| 404 only from some networks | Your IP is outside `ADMIN_ALLOWED_IPS`. Fix the list (below) or clear it, then `docker compose up -d`. |
| Lost the authenticator and recovery codes | Clear 2FA from the CLI (below). |
| Forgot the password | Use "Forgot password" on the login page. With `MAIL_MAILER=log` the reset link is written to the log instead of sent: `docker compose exec app tail -n 100 storage/logs/laravel.log`. The mail is queued, so Horizon and Redis must be running. |
| Password confirmation keeps appearing | Expected. `/admin` asks for your password again after `ADMIN_PASSWORD_TIMEOUT` seconds (default 900). |

### Reset a user's or admin's 2FA

```sh
docker compose exec app php artisan michicast:clear-two-factor admin@example.com
```

This removes the TOTP secret and recovery codes (also a half-finished enrollment). The user signs in with the password only and can enroll again from their profile. 2FA is optional for admins.

### Restrict `/admin` by IP

```sh
# deploy/.env, comma-separated, CIDR allowed. Empty = no restriction.
ADMIN_ALLOWED_IPS=203.0.113.10,198.51.100.0/24
```

Then `docker compose up -d`. The address checked is the client IP that Caddy forwards. The list also covers Horizon and Pulse.

## Admin area

All pages live under `/admin` and are linked from the admin sidebar. Every change asks for a reason and is written to the audit log.

| Page | Path | Use it for |
| --- | --- | --- |
| Overview | `/admin` | Counts of users, organizations and active broadcasts, runs by status over the last 24 hours, failed job count. |
| Users | `/admin/users` | Find a user, see their memberships, ban or unban. |
| Organizations | `/admin/organizations` | Find an organization, suspend or reinstate, change plan, set quota overrides, reset today's usage. |
| Activity | `/admin/activity` | Runs across all organizations with filters, and a "Needs attention" summary. Errors are shown redacted. |
| Audit | `/admin/audit` | Every admin action: sign-ins to `/admin`, bans, suspensions, plan and quota changes, registration changes. |
| Settings | `/admin/settings` | Registration mode and daily cap. |
| Horizon | `/admin/horizon` | Queue dashboard. |
| Pulse | `/admin/pulse` | Performance dashboard. |

## Ban and unban a user

`/admin/users/{user}`, card **Ban user**: enter a reason, tick the confirmation and press **Ban user**. Optionally tick "Also suspend every organization where they are the only owner".

A ban signs the user out everywhere (sessions deleted, remember-me token rotated), blocks sign-in and password reset, and blocks invitation acceptance. Nothing is deleted. You cannot ban yourself or a platform admin.

**Unban user** on the same page lifts the ban. Old sessions are not restored and organizations suspended by the ban stay suspended until you reinstate them.

## Suspend and reinstate an organization

`/admin/organizations/{organization}`, card **Suspend organization**: enter a reason, tick the confirmation and press **Suspend organization**. Members can still view the organization but every change is refused, and its broadcasts stop running.

**Reinstate organization** lifts the suspension. Schedules resume from now; missed runs are not replayed.

## Plans, overrides and usage

In the **Quotas** card on `/admin/organizations/{organization}` (every action needs a reason):

- **Change plan**: pick `free` or `unlimited` (defined in `config/michicast.php`). New organizations get `QUOTA_DEFAULT_PLAN` (default `free`). Free-plan limits come from the `QUOTA_FREE_*` env keys. Rate limits (source fetches, deliveries) count per UTC calendar month by default (`QUOTA_FREE_*_PERIOD`); **Set period** on the Quotas card picks daily, weekly or monthly for one organization.
- **Set override** / **Update override**: per metric, set a limit or tick "Unlimited for this metric", with an optional note. An override wins over the plan. **Clear override** returns the metric to the plan limit.
- **Reset usage**: sets today's counter for a metric back to zero. Daily metrics (source fetches, deliveries) reset at midnight UTC anyway.

## Registration

`/admin/settings` sets the mode and an optional daily cap (press **Save registration settings** with a reason):

| Mode | Effect |
| --- | --- |
| `open` | Anyone can register. |
| `invite_only` | Only emails with a pending, unexpired invitation can register. |
| `closed` | Nobody can register. Existing users can still log in. |

The daily cap closes registration once that many accounts were created since midnight UTC. Leave it empty for no cap.

The page values are stored in the database and win over the env defaults `MICHICAST_REGISTRATION` (default `open`) and `MICHICAST_REGISTRATION_DAILY_CAP`. Register the first admin before closing registration.

## Horizon

`/admin/horizon` shows queued, completed and failed jobs for the `default` and `mail` queues. If it shows nothing at all, Horizon or Redis is down (see [Redis is down](#redis-is-down)).

- **Failed jobs**: open *Failed Jobs*, inspect the exception, and press **Retry**. From the CLI:

  ```sh
  docker compose exec app php artisan queue:failed
  docker compose exec app php artisan queue:retry all
  docker compose exec app php artisan horizon:forget <id>
  ```

  Failed job rows older than 48 hours are pruned daily.

- **Status**: `docker compose exec app php artisan horizon:status`.
- **Restart workers** (after a manual code or env change; `deploy.sh` already does this):

  ```sh
  docker compose exec app php artisan horizon:terminate
  ```

  Horizon exits after its current jobs and Docker restarts the `horizon` container.

Logs: `docker compose logs -f horizon`.

## Pulse

`/admin/pulse` shows application usage (top users), queues, cache, slow queries, exceptions, slow requests, slow jobs and slow outgoing requests. Data is kept for 7 days and is written straight to MySQL, so no extra worker is needed.

There is no Servers card: it needs a `pulse:check` service, which the 1 GB droplet does not run. To add it later, add a Compose service like `horizon` running `php artisan pulse:check`, set `PULSE_SERVER_NAME`, and restore `<livewire:pulse.servers />` in `resources/views/vendor/pulse/dashboard.blade.php`.

## Backups

Back up:

| What | Why |
| --- | --- |
| MySQL database (`mysql-data` volume) | All application data. Take a logical dump rather than copying the volume. |
| `deploy/.env` | Holds `APP_KEY`. Without the same key, destination credentials and 2FA secrets in the dump cannot be decrypted. |
| Redis AOF (`redis-data` volume) | Optional. Only queued jobs and Horizon metrics. Losing it drops jobs that had not run yet; scheduled runs recover on their own. |

Certificates in `caddy-data` are reissued automatically if lost.

Dump the database and copy the env file, then move both off the droplet:

```sh
docker compose exec -T mysql sh -c 'exec mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction "$MYSQL_DATABASE"' | gzip > ~/michicast-$(date +%F).sql.gz
cp deploy/.env ~/michicast-env-$(date +%F)
```

### Restore check

Load a dump into a scratch database and confirm it has data, without touching the live one:

```sh
docker compose exec -T mysql sh -c 'exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "CREATE DATABASE restore_check"'
gunzip -c ~/michicast-YYYY-MM-DD.sql.gz | docker compose exec -T mysql sh -c 'exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD" restore_check'
docker compose exec -T mysql sh -c 'exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "SELECT COUNT(*) FROM restore_check.users; SELECT COUNT(*) FROM restore_check.migrations;"'
docker compose exec -T mysql sh -c 'exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "DROP DATABASE restore_check"'
```

### Full restore

Stop the PHP services, load the dump into the live database, put the matching `deploy/.env` back, then start everything:

```sh
docker compose stop app horizon scheduler
gunzip -c ~/michicast-YYYY-MM-DD.sql.gz | docker compose exec -T mysql sh -c 'exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"'
docker compose up -d
docker compose exec app php artisan migrate --force
```

## Redis is down

Redis carries the queue only; sessions and cache are in MySQL, so pages and sign-in keep working.

While Redis is down:

- `/admin/horizon` shows nothing: Horizon keeps all its state in Redis and its workers cannot run.
- Anything that queues a job fails: scheduled broadcast runs, Run now, Send latest, invitation emails and password-reset emails. Users see an error and can retry later.
- The scheduler still claims due broadcasts, but cannot queue them, so those claims stay stuck.

Check and restart it:

```sh
docker compose ps redis
docker compose logs --tail=100 redis
docker compose up -d redis
```

Once Redis is back, nothing else is needed. Claims older than `DISPATCH_STALE_CLAIM_AFTER` seconds (default 120) are taken again on the next scheduler tick, and Horizon picks up new jobs. Redis is capped at 64 MB with `noeviction`; if it filled up, new jobs are refused until the queue drains.

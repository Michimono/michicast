#!/usr/bin/env bash
# Michicast production deploy. Run as the deploy user from anywhere:
#
#   cd ~/michimono && git pull --ff-only && bash deploy/deploy.sh
#
# First run: creates deploy/.env from deploy/.env.example, generates secrets,
# and asks for the values only you know (domain, emails, mail). Later runs ask
# nothing unless a required value is missing, then deploy.
#
#   bash deploy/deploy.sh --env-only   # check/complete deploy/.env, no deploy
set -euo pipefail

cd "$(dirname "$0")/.."
ENV_FILE=deploy/.env
EXAMPLE_FILE=deploy/.env.example
ENV_ONLY=false
[ "${1:-}" = "--env-only" ] && ENV_ONLY=true

say()  { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33mWARN:\033[0m %s\n' "$*" >&2; }
die()  { printf '\033[1;31mERROR:\033[0m %s\n' "$*" >&2; exit 1; }

# --- .env helpers ----------------------------------------------------------

# Value of KEY (last uncommented assignment), surrounding quotes removed.
get_env() {
    local line
    line=$(grep -E "^$1=" "$ENV_FILE" | tail -n 1 || true)
    line=${line#*=}
    line=${line%$'\r'}
    case $line in
        \"*\") line=${line#\"}; line=${line%\"} ;;
        \'*\') line=${line#\'}; line=${line%\'} ;;
    esac
    printf '%s' "$line"
}

# Set KEY=VALUE: replaces an existing or commented-out line, else appends.
# Values with spaces or shell/compose specials are written single-quoted.
set_env() {
    local key=$1 value=$2 out tmp
    case $value in
        *\'*) die "$key cannot contain a single quote (') character." ;;
    esac
    if [[ $value =~ ^[A-Za-z0-9@._:/+=,-]*$ ]]; then out="$key=$value"; else out="$key='$value'"; fi
    tmp=$(mktemp)
    KEY="$key" LINE="$out" awk '
        BEGIN { key = ENVIRON["KEY"]; line = ENVIRON["LINE"]; done = 0 }
        !done && ($0 ~ "^" key "=" || $0 ~ "^#[ \t]*" key "=") { print line; done = 1; next }
        $0 ~ "^" key "=" { next }
        { print }
        END { if (!done) print line }
    ' "$ENV_FILE" > "$tmp"
    cat "$tmp" > "$ENV_FILE"
    rm -f "$tmp"
}

# True when the value is empty or still an example placeholder.
is_placeholder() {
    case $1 in
        ''|*change-me*|example.com|*@example.com|https://example.com|http://example.com|http://localhost) return 0 ;;
        *) return 1 ;;
    esac
}

HAS_TTY=false
if [ -r /dev/tty ] && { : < /dev/tty; } 2>/dev/null; then HAS_TTY=true; fi
MISSING=()

# ask KEY "question" [default] [secret] [validator]
ask() {
    local key=$1 question=$2 default=${3:-} secret=${4:-} validator=${5:-} answer
    if [ "$HAS_TTY" != true ]; then MISSING+=("$key"); return 1; fi
    while true; do
        if [ -n "$default" ]; then printf '%s [%s]: ' "$question" "$default" > /dev/tty
        else printf '%s: ' "$question" > /dev/tty; fi
        if [ "$secret" = secret ]; then IFS= read -rs answer < /dev/tty; echo > /dev/tty
        else IFS= read -r answer < /dev/tty; fi
        answer=${answer:-$default}
        answer=$(printf '%s' "$answer" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')
        if [ -z "$answer" ]; then echo "  A value is required." > /dev/tty; continue; fi
        if [ -n "$validator" ] && ! "$validator" "$answer"; then continue; fi
        set_env "$key" "$answer"
        return 0
    done
}

valid_domain() {
    [[ $1 =~ ^[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?)+$ ]] && return 0
    echo "  Enter just the hostname, e.g. cast.example.org (no https://, no path)." > /dev/tty; return 1
}
valid_email() {
    [[ $1 =~ ^[^@[:space:],]+@[^@[:space:],]+\.[^@[:space:],]+$ ]] && ! is_placeholder "$1" && return 0
    echo "  Enter a real email address." > /dev/tty; return 1
}
valid_email_list() {
    local e
    IFS=',' read -ra emails <<< "$1"
    for e in "${emails[@]}"; do
        e=$(printf '%s' "$e" | tr -d '[:space:]')
        valid_email "$e" || return 1
    done
}
valid_port() {
    [[ $1 =~ ^[0-9]+$ ]] && [ "$1" -ge 1 ] && [ "$1" -le 65535 ] && return 0
    echo "  Enter a port number, e.g. 587." > /dev/tty; return 1
}

gen_hex() { openssl rand -hex "$1"; }

# --- 1. Make sure deploy/.env exists ----------------------------------------

[ -f "$EXAMPLE_FILE" ] || die "$EXAMPLE_FILE is missing. Run git pull in $(pwd)."
if [ ! -f "$ENV_FILE" ]; then
    say "Creating $ENV_FILE from $EXAMPLE_FILE"
    cp "$EXAMPLE_FILE" "$ENV_FILE"
fi
chmod 600 "$ENV_FILE"
command -v openssl > /dev/null || die "openssl is required (sudo apt-get install -y openssl)."

# --- 2. Values only you can provide ------------------------------------------

domain=$(get_env DOMAIN)
if is_placeholder "$domain"; then
    say "Public hostname for Michicast (DNS A record must point at this droplet)."
    ask DOMAIN "Domain" "" "" valid_domain || true
    domain=$(get_env DOMAIN)
fi

if is_placeholder "$(get_env ACME_EMAIL)"; then
    ask ACME_EMAIL "Email for Let's Encrypt certificate notices" "" "" valid_email || true
fi

if is_placeholder "$(get_env PLATFORM_ADMIN_EMAILS)"; then
    say "Platform admins can open /admin. Register with one of these emails after deploying."
    ask PLATFORM_ADMIN_EMAILS "Platform admin email(s), comma-separated" "$(get_env ACME_EMAIL)" "" valid_email_list || true
fi

# Mail: SMTP, or an explicit choice to keep the log mailer (asked once).
mailer=$(get_env MAIL_MAILER)
if [ "$mailer" = log ] && [ "$(get_env DEPLOY_ALLOW_LOG_MAILER)" != true ]; then
    if [ "$HAS_TTY" = true ]; then
        say "Mail is set to 'log': password resets and invites are written to the log, not sent."
        printf 'Configure SMTP now? [Y/n]: ' > /dev/tty
        IFS= read -r reply < /dev/tty
        case ${reply:-y} in
            [nN]*)
                set_env DEPLOY_ALLOW_LOG_MAILER true
                warn "Keeping MAIL_MAILER=log. Set MAIL_MAILER=smtp in $ENV_FILE when you're ready." ;;
            *)
                set_env MAIL_MAILER smtp ;;
        esac
    else
        MISSING+=(MAIL_MAILER)
    fi
    mailer=$(get_env MAIL_MAILER)
fi
if [ "$mailer" = smtp ]; then
    [ -n "$(get_env MAIL_HOST)" ]     || ask MAIL_HOST "SMTP host" "" || true
    [ -n "$(get_env MAIL_PORT)" ]     || ask MAIL_PORT "SMTP port" "587" "" valid_port || true
    [ -n "$(get_env MAIL_USERNAME)" ] || ask MAIL_USERNAME "SMTP username" || true
    [ -n "$(get_env MAIL_PASSWORD)" ] || ask MAIL_PASSWORD "SMTP password (hidden)" "" secret || true
fi
if [ "$mailer" != log ] && is_placeholder "$(get_env MAIL_FROM_ADDRESS)"; then
    ask MAIL_FROM_ADDRESS "Send emails from" "no-reply@${domain:-example.org}" "" valid_email || true
fi

if [ ${#MISSING[@]} -gt 0 ]; then
    die "deploy/.env needs: ${MISSING[*]}. Run this script in an interactive terminal, or set them in $ENV_FILE."
fi

# --- 3. Generated secrets and enforced production settings ------------------

mysql_volume_exists=false
if command -v docker > /dev/null && docker volume inspect michicast_mysql-data > /dev/null 2>&1; then
    mysql_volume_exists=true
fi

if is_placeholder "$(get_env APP_KEY)"; then
    [ "$mysql_volume_exists" = true ] && die "APP_KEY is empty but this droplet already has data. Restore the original APP_KEY into $ENV_FILE; a new key can't decrypt stored credentials."
    say "Generating APP_KEY (back up deploy/.env: this key decrypts stored credentials)"
    set_env APP_KEY "base64:$(openssl rand -base64 32)"
fi

db_pw=$(get_env DB_PASSWORD)
mysql_pw=$(get_env MYSQL_PASSWORD)
if is_placeholder "$db_pw" && is_placeholder "$mysql_pw"; then
    [ "$mysql_volume_exists" = true ] && die "The MySQL volume already exists but DB_PASSWORD is a placeholder. Put the real database password in $ENV_FILE (DB_PASSWORD and MYSQL_PASSWORD)."
    say "Generating database password"
    db_pw=$(gen_hex 24)
    set_env DB_PASSWORD "$db_pw"
    set_env MYSQL_PASSWORD "$db_pw"
elif is_placeholder "$mysql_pw"; then
    set_env MYSQL_PASSWORD "$db_pw"
elif is_placeholder "$db_pw"; then
    set_env DB_PASSWORD "$mysql_pw"
elif [ "$db_pw" != "$mysql_pw" ]; then
    die "DB_PASSWORD and MYSQL_PASSWORD differ in $ENV_FILE. They must be the same password."
fi
if is_placeholder "$(get_env MYSQL_ROOT_PASSWORD)"; then
    [ "$mysql_volume_exists" = true ] && die "The MySQL volume already exists but MYSQL_ROOT_PASSWORD is a placeholder. Put the real root password in $ENV_FILE."
    say "Generating MySQL root password"
    set_env MYSQL_ROOT_PASSWORD "$(gen_hex 24)"
fi
if is_placeholder "$(get_env REDIS_PASSWORD)"; then
    say "Generating Redis password"
    set_env REDIS_PASSWORD "$(gen_hex 32)"
fi

set_if_different() {
    if [ "$(get_env "$1")" != "$2" ]; then
        say "Setting $1=$2"
        set_env "$1" "$2"
    fi
}
set_if_different APP_ENV production
set_if_different APP_DEBUG false
set_if_different SESSION_SECURE_COOKIE true
set_if_different APP_URL "https://$domain"

[ "$(get_env MAIL_MAILER)" = log ] && warn "MAIL_MAILER=log: emails are only written to the log. Set up SMTP in $ENV_FILE before inviting users."
say "deploy/.env is complete and production-ready."
if [ "$ENV_ONLY" = true ]; then exit 0; fi

# --- 4. Deploy -----------------------------------------------------------------

docker info > /dev/null 2>&1 || die "Can't talk to Docker. If you were just added to the docker group, log out and back in."

say "Building images (pulling fresh base images)"
docker compose build --pull
say "Starting containers"
docker compose up -d --remove-orphans --wait --wait-timeout 300
say "Running migrations"
docker compose exec -T app php artisan migrate --force
say "Restarting Horizon on the new code"
docker compose exec -T app php artisan horizon:terminate
say "Restarting web so nginx picks up the new app container"
docker compose restart web

say "Checking https://$domain/up"
for _ in 1 2 3 4 5 6; do
    if curl -fsS -o /dev/null --max-time 10 "https://$domain/up"; then
        say "Deployed. https://$domain is up."
        exit 0
    fi
    sleep 10
done
die "https://$domain/up did not answer. Check: docker compose ps -a; docker compose logs --tail=100 web app caddy"

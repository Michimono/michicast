#!/bin/sh
# First boot of an Ubuntu 26.04 droplet, as root. Does not clone the repo.
set -eu

if [ ! -t 0 ]; then
    echo "Run deploy/setup-droplet.sh in an interactive shell." >&2
    exit 1
fi

if [ "$(id -u)" -ne 0 ]; then
    exec sudo -- "$0" "$@"
fi

trap 'stty echo 2>/dev/null || true' EXIT

read_secret() {
    printf '%s' "$1" >&2
    stty -echo
    IFS= read -r secret || {
        stty echo
        printf '\n' >&2
        exit 1
    }
    stty echo
    printf '\n' >&2
}

mysql_ident() {
    printf '%s' "$1" | grep -qE '^[A-Za-z0-9_]+$'
}

quote_env() {
    ENV_RAW="$1" awk '
        BEGIN {
            s = ENVIRON["ENV_RAW"]
            out = "\""
            n = length(s)
            for (i = 1; i <= n; i++) {
                c = substr(s, i, 1)
                if (c == "\\") out = out "\\\\"
                else if (c == "\"") out = out "\\\""
                else if (c == "$") out = out "$$"
                else out = out c
            }
            out = out "\""
            printf "%s\n", out
        }
    '
}

set_env_key() {
    tmp=$(mktemp)
    ENV_KEY=$2 ENV_VAL=$(quote_env "$3") awk '
        BEGIN { key = ENVIRON["ENV_KEY"]; val = ENVIRON["ENV_VAL"]; found = 0 }
        {
            if (index($0, key "=") == 1) {
                print key "=" val
                found = 1
                next
            }
            print
        }
        END { if (found == 0) print key "=" val }
    ' "$1" > "$tmp"
    mv "$tmp" "$1"
}

primary_ipv4() {
    hostname -I 2>/dev/null | tr ' ' '\n' | awk '/^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$/ && $0 != "127.0.0.1" { print; exit }'
}

random_secret() {
    secret=$(openssl rand -base64 32) || {
        echo "Could not generate a random secret." >&2
        exit 1
    }
    secret=$(printf '%s' "$secret" | tr -d '\n\r')
    if [ -z "$secret" ]; then
        echo "Could not generate a random secret." >&2
        exit 1
    fi
    printf '%s' "$secret"
}

printf 'Ubuntu username [michicast]: ' >&2
IFS= read -r ubuntu_user || exit 1
if [ -z "$ubuntu_user" ]; then
    ubuntu_user=michicast
fi
if [ "$ubuntu_user" = "root" ]; then
    echo "Ubuntu username cannot be root." >&2
    exit 1
fi
if ! printf '%s' "$ubuntu_user" | grep -qE '^[A-Za-z_][A-Za-z0-9_-]*$'; then
    echo "Ubuntu username must contain only letters, digits, underscore, or hyphen, and must not start with a digit." >&2
    exit 1
fi
if [ "${#ubuntu_user}" -gt 32 ]; then
    echo "Ubuntu username must be 32 characters or fewer." >&2
    exit 1
fi

read_secret "Ubuntu login password: "
ubuntu_pass=$secret
read_secret "Confirm Ubuntu login password: "
if [ -z "$ubuntu_pass" ]; then
    echo "Ubuntu login password is empty." >&2
    exit 1
fi
if [ "$ubuntu_pass" != "$secret" ]; then
    echo "Ubuntu login password confirmation does not match." >&2
    exit 1
fi

printf 'MySQL username: ' >&2
IFS= read -r mysql_user || exit 1
if ! mysql_ident "$mysql_user"; then
    echo "MySQL username must contain only letters, digits, and underscore." >&2
    exit 1
fi

read_secret "MySQL password: "
mysql_pass=$secret
read_secret "Confirm MySQL password: "
if [ -z "$mysql_pass" ]; then
    echo "MySQL password is empty." >&2
    exit 1
fi
if [ "$mysql_pass" != "$secret" ]; then
    echo "MySQL password confirmation does not match." >&2
    exit 1
fi

printf 'Database name: ' >&2
IFS= read -r mysql_database || exit 1
if ! mysql_ident "$mysql_database"; then
    echo "Database name must contain only letters, digits, and underscore." >&2
    exit 1
fi
secret=

if ! swapon --show --noheadings | grep -q .; then
    if [ ! -f /swapfile ]; then
        fallocate -l 1G /swapfile
        chmod 600 /swapfile
        mkswap /swapfile
    fi
    swapon /swapfile
fi

if [ -f /swapfile ] && ! grep -qE '^[[:space:]]*/swapfile[[:space:]]' /etc/fstab; then
    echo '/swapfile none swap sw 0 0' >> /etc/fstab
fi

if ! docker compose version >/dev/null 2>&1; then
    apt-get update
    apt-get install -y ca-certificates curl
    install -m 0755 -d /etc/apt/keyrings
    curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
    chmod a+r /etc/apt/keyrings/docker.asc
    . /etc/os-release
    echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu ${VERSION_CODENAME} stable" > /etc/apt/sources.list.d/docker.list
    apt-get update
    apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
fi

systemctl enable --now docker

if ! id -u "$ubuntu_user" >/dev/null 2>&1; then
    useradd --create-home --shell /bin/bash "$ubuntu_user"
fi
usermod -aG docker,sudo "$ubuntu_user"
printf '%s:%s\n' "$ubuntu_user" "$ubuntu_pass" | chpasswd
unset ubuntu_pass
if [ "$(LC_ALL=C passwd -S "$ubuntu_user" | awk '{print $2}')" = "L" ]; then
    passwd -u "$ubuntu_user" >/dev/null
fi

sudoers=/etc/sudoers.d/$ubuntu_user
printf '%s\n' "$ubuntu_user ALL=(ALL) NOPASSWD:ALL" > "$sudoers"
chmod 440 "$sudoers"
visudo -cf "$sudoers" >/dev/null

home=$(getent passwd "$ubuntu_user" | cut -d: -f6)
group=$(id -gn "$ubuntu_user")
if [ -z "$home" ] || [ ! -d "$home" ]; then
    echo "Could not find a home directory for ${ubuntu_user}." >&2
    exit 1
fi

install -d -m 700 -o "$ubuntu_user" -g "$group" "$home/.ssh"
if [ ! -s "$home/.ssh/authorized_keys" ] && [ -s /root/.ssh/authorized_keys ]; then
    cp /root/.ssh/authorized_keys "$home/.ssh/authorized_keys"
    chown "$ubuntu_user:$group" "$home/.ssh/authorized_keys"
    chmod 600 "$home/.ssh/authorized_keys"
fi

if [ -d /root/michicast/.git ] && [ ! -d "$home/michicast/.git" ]; then
    if [ -d "$home/michicast" ]; then
        rmdir "$home/michicast"
    fi
    mv /root/michicast "$home/michicast"
fi
if [ -d "$home/michicast" ]; then
    chown -R "$ubuntu_user:$group" "$home/michicast"
fi

env_example=$home/michicast/deploy/.env.example
env_file=$home/michicast/deploy/.env
if [ ! -f "$env_example" ]; then
    echo "Missing ${home}/michicast/deploy/.env.example." >&2
    exit 1
fi

old_umask=$(umask)
umask 077
env_existed=0
if [ -f "$env_file" ]; then
    env_existed=1
    target=$env_file
else
    if ! command -v openssl >/dev/null 2>&1; then
        apt-get update
        apt-get install -y openssl
    fi
    app_key=base64:$(random_secret)
    root_pass=$(random_secret)
    target=$(mktemp)
    cp "$env_example" "$target"
    set_env_key "$target" APP_KEY "$app_key"
    set_env_key "$target" MYSQL_ROOT_PASSWORD "$root_pass"
    unset app_key root_pass
fi

set_env_key "$target" DB_DATABASE "$mysql_database"
set_env_key "$target" DB_USERNAME "$mysql_user"
set_env_key "$target" DB_PASSWORD "$mysql_pass"
set_env_key "$target" MYSQL_DATABASE "$mysql_database"
set_env_key "$target" MYSQL_USER "$mysql_user"
set_env_key "$target" MYSQL_PASSWORD "$mysql_pass"
unset mysql_pass

app_url=$(awk 'index($0, "APP_URL=") == 1 { print substr($0, 9); exit }' "$target")
if [ "$app_url" = "https://example.com" ]; then
    ip=$(primary_ipv4)
    if [ -z "$ip" ]; then
        if [ "$env_existed" -eq 0 ]; then
            rm -f "$target"
        fi
        echo "Could not find a primary IPv4 address for APP_URL." >&2
        exit 1
    fi
    set_env_key "$target" APP_URL "http://${ip}"
fi

if [ "$env_existed" -eq 0 ]; then
    mv "$target" "$env_file"
fi
chown "$ubuntu_user:$group" "$env_file"
chmod 600 "$env_file"
umask "$old_umask"

if [ "$env_existed" -eq 1 ] || docker volume inspect michicast_mysql-data >/dev/null 2>&1; then
    echo "MySQL only applies MYSQL_USER, MYSQL_PASSWORD, and MYSQL_DATABASE the first time the data volume is empty, so a changed password in deploy/.env will not alter an already-initialized database."
fi

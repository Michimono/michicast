#!/bin/sh
# First boot of an Ubuntu 26.04 droplet, as root. Does not clone the repo.
set -eu

if [ "$(id -u)" -ne 0 ]; then
    exec sudo -- "$0" "$@"
fi

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

if ! id -u michicast >/dev/null 2>&1; then
    useradd --create-home --shell /bin/bash michicast
fi
usermod -aG docker,sudo michicast
passwd -l michicast >/dev/null

sudoers=/etc/sudoers.d/michicast
printf '%s\n' 'michicast ALL=(ALL) NOPASSWD:ALL' > "$sudoers"
chmod 440 "$sudoers"
visudo -cf "$sudoers" >/dev/null

install -d -m 700 -o michicast -g michicast /home/michicast/.ssh
if [ ! -s /home/michicast/.ssh/authorized_keys ] && [ -s /root/.ssh/authorized_keys ]; then
    cp /root/.ssh/authorized_keys /home/michicast/.ssh/authorized_keys
    chown michicast:michicast /home/michicast/.ssh/authorized_keys
    chmod 600 /home/michicast/.ssh/authorized_keys
fi

if [ -d /root/michicast/.git ] && [ ! -d /home/michicast/michicast/.git ]; then
    if [ -d /home/michicast/michicast ]; then
        rmdir /home/michicast/michicast
    fi
    mv /root/michicast /home/michicast/michicast
fi
if [ -d /home/michicast/michicast ]; then
    chown -R michicast:michicast /home/michicast/michicast
fi

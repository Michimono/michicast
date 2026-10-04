#!/bin/sh
# First boot of an Ubuntu 26.04 droplet. Does not clone the repo.
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

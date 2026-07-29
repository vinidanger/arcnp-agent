#!/bin/bash
# Uso: delete-hosting-user.sh <username>
set -euo pipefail

USERNAME="${1:-}"

if [[ ! "$USERNAME" =~ ^[a-z][a-z0-9]{2,31}$ ]]; then
    echo "Username inválido: $USERNAME" >&2
    exit 1
fi

if ! id -u "$USERNAME" >/dev/null 2>&1; then
    echo "Usuário não existe: $USERNAME" >&2
    exit 0
fi

userdel -r "$USERNAME"
rm -f "/etc/cron.d/arcnp-$USERNAME"

echo "OK"

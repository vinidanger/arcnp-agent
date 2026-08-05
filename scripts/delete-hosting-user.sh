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


# Defesa extra além do clear explícito de limites que o Painel já
# dispara antes desse script (resources.set_limits com infinity) —
# se por algum motivo esse passo não rodou, isso evita que o slice
# fique "lingering" indefinidamente pra uma UID que está prestes a
# ser reciclada por um useradd futuro.
loginctl disable-linger "$USERNAME" 2>/dev/null || true

userdel -r "$USERNAME"
rm -f "/etc/cron.d/arcnp-$USERNAME"

echo "OK"

#!/bin/bash
# Uso: create-hosting-user.sh <username>
#
# Autorizado via sudoers (deploy/sudoers/arcnp-agent) com argumentos
# livres — a validação do username é feita AQUI, não no sudoers, como
# defesa em profundidade (o Agent em PHP já valida antes de chamar,
# mas o script nunca confia só nisso).
set -euo pipefail

USERNAME="${1:-}"

if [[ ! "$USERNAME" =~ ^[a-z][a-z0-9]{2,31}$ ]]; then
    echo "Username inválido: $USERNAME" >&2
    exit 1
fi

HOME_DIR="/home/$USERNAME"

if id -u "$USERNAME" >/dev/null 2>&1; then
    echo "Usuário já existe: $USERNAME" >&2
    exit 1
fi

useradd -m -d "$HOME_DIR" -s /sbin/nologin "$USERNAME"
mkdir -p "$HOME_DIR/public_html" "$HOME_DIR/logs"
chown -R "$USERNAME:$USERNAME" "$HOME_DIR/public_html" "$HOME_DIR/logs"
chmod 750 "$HOME_DIR" "$HOME_DIR/public_html" "$HOME_DIR/logs"

echo "OK"

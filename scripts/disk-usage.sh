#!/bin/bash
# Uso: disk-usage.sh <username>
#
# Precisa de root porque soma o home inteiro (public_html + backups/ +
# logs/) — o Agent só tem ACL de leitura em public_html e nos arquivos
# de backup específicos, não no home todo (logs/ nunca foi liberado
# pra ele, por exemplo). Imprime só o total em bytes.
set -euo pipefail

USERNAME="${1:-}"

if [[ ! "$USERNAME" =~ ^[a-z][a-z0-9]{2,31}$ ]]; then
    echo "Username inválido: $USERNAME" >&2
    exit 1
fi

HOME_DIR="/home/$USERNAME"

if [[ ! -d "$HOME_DIR" ]]; then
    echo "Home não existe: $HOME_DIR" >&2
    exit 1
fi

du -sb "$HOME_DIR" | cut -f1

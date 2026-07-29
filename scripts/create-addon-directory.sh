#!/bin/bash
# Uso: create-addon-directory.sh <username> <subdir>
#
# Cria o subdiretório dentro de public_html usado por domínio adicional
# ou subdomínio. Herda a ACL padrão do nginx automaticamente (definida
# em create-hosting-user.sh via `setfacl -d`), não precisa repetir aqui.
set -euo pipefail

USERNAME="${1:-}"
SUBDIR="${2:-}"

if [[ ! "$USERNAME" =~ ^[a-z][a-z0-9]{2,31}$ ]]; then
    echo "Username inválido: $USERNAME" >&2
    exit 1
fi

if [[ ! "$SUBDIR" =~ ^[a-z0-9][a-z0-9_-]{0,63}$ ]]; then
    echo "Subdiretório inválido: $SUBDIR" >&2
    exit 1
fi

DOC_ROOT="/home/$USERNAME/public_html/$SUBDIR"

if [[ -e "$DOC_ROOT" ]]; then
    echo "Diretório já existe: $DOC_ROOT" >&2
    exit 1
fi

mkdir -p "$DOC_ROOT"
chown "$USERNAME:$USERNAME" "$DOC_ROOT"
chmod 750 "$DOC_ROOT"

echo "OK"

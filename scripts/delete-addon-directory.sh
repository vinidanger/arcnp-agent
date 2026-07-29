#!/bin/bash
# Uso: delete-addon-directory.sh <username> <subdir>
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

if [[ -d "$DOC_ROOT" ]]; then
    rm -rf "$DOC_ROOT"
fi

echo "OK"

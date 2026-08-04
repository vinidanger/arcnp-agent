#!/bin/bash
# Uso: delete-addon-directory.sh <username> <location> <target>
set -euo pipefail

USERNAME="${1:-}"
LOCATION="${2:-}"
TARGET="${3:-}"

if [[ ! "$USERNAME" =~ ^[a-z][a-z0-9]{2,31}$ ]]; then
    echo "Username inválido: $USERNAME" >&2
    exit 1
fi

HOME_DIR="/home/$USERNAME"

case "$LOCATION" in
    inside)
        if [[ ! "$TARGET" =~ ^[a-z0-9][a-z0-9_-]{0,63}$ ]]; then
            echo "Subdiretório inválido: $TARGET" >&2
            exit 1
        fi

        DOC_ROOT="$HOME_DIR/public_html/$TARGET"
        [[ -d "$DOC_ROOT" ]] && rm -rf "$DOC_ROOT"
        ;;
    outside)
        if [[ ! "$TARGET" =~ ^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$ ]]; then
            echo "Domínio inválido: $TARGET" >&2
            exit 1
        fi

        DOMAIN_DIR="$HOME_DIR/domains/$TARGET"
        [[ -d "$DOMAIN_DIR" ]] && rm -rf "$DOMAIN_DIR"
        ;;
    *)
        echo "Location inválida: $LOCATION" >&2
        exit 1
        ;;
esac

echo "OK"

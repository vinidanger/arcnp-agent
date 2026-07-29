#!/bin/bash
# Uso: create-addon-directory.sh <username> <location> <target>
#
# location=inside: target é um subdiretório dentro de public_html —
#   herda a ACL padrão do nginx/arcnpagent automaticamente (definida em
#   create-hosting-user.sh via `setfacl -d`), não precisa repetir aqui.
# location=outside: target é o nome do domínio, cria uma árvore própria
#   em domains/<target>/public_html (mesma convenção do DirectAdmin) —
#   como é uma árvore nova, fora do que já tem ACL default, precisa
#   conceder nginx e arcnpagent aqui explicitamente.
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

        if [[ -e "$DOC_ROOT" ]]; then
            echo "Diretório já existe: $DOC_ROOT" >&2
            exit 1
        fi

        mkdir -p "$DOC_ROOT"
        chown "$USERNAME:$USERNAME" "$DOC_ROOT"
        chmod 750 "$DOC_ROOT"
        ;;
    outside)
        if [[ ! "$TARGET" =~ ^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$ ]]; then
            echo "Domínio inválido: $TARGET" >&2
            exit 1
        fi

        DOMAIN_DIR="$HOME_DIR/domains/$TARGET"
        DOC_ROOT="$DOMAIN_DIR/public_html"

        if [[ -e "$DOMAIN_DIR" ]]; then
            echo "Diretório já existe: $DOMAIN_DIR" >&2
            exit 1
        fi

        mkdir -p "$DOC_ROOT"
        chown -R "$USERNAME:$USERNAME" "$HOME_DIR/domains"
        chmod 750 "$HOME_DIR/domains" "$DOMAIN_DIR" "$DOC_ROOT"

        setfacl -m u:nginx:x "$HOME_DIR/domains" "$DOMAIN_DIR"
        setfacl -R -m u:nginx:rx "$DOC_ROOT"
        setfacl -d -m u:nginx:rx "$DOC_ROOT"

        setfacl -m u:arcnpagent:x "$HOME_DIR/domains" "$DOMAIN_DIR"
        setfacl -R -m u:arcnpagent:rx "$DOC_ROOT"
        setfacl -d -m u:arcnpagent:rx "$DOC_ROOT"
        ;;
    *)
        echo "Location inválida: $LOCATION" >&2
        exit 1
        ;;
esac

echo "OK"

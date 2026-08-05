#!/bin/bash
# Uso: manage-cli-zend-profile.sh <username>
#
# Reescreve só o trecho entre marcadores dentro do ~/.bashrc da conta
# (nunca o arquivo inteiro — preserva qualquer customização que o
# dono tenha feito) com wrappers de shell que ativam zend_extension
# (ioncube etc.) também pro PHP CLI via SSH, espelhando o que já vale
# pro PHP-FPM da conta (ver PHP_INI_SCAN_DIR em
# SyncAccountPhpPoolsAction). O bloco novo vem pelo STDIN, já pronto
# (uma função de shell por linha); vazio remove o bloco inteiro.
set -euo pipefail

USERNAME="${1:-}"

if [[ ! "$USERNAME" =~ ^[a-z][a-z0-9]{2,31}$ ]]; then
    echo "Username inválido: $USERNAME" >&2
    exit 1
fi

HOME_DIR="/home/$USERNAME"
BASHRC="$HOME_DIR/.bashrc"
BEGIN="# BEGIN ARCNP-PHP-CLI (gerado automaticamente pelo Painel — não editar)"
END="# END ARCNP-PHP-CLI"

if [[ ! -d "$HOME_DIR" ]]; then
    echo "Home não existe: $HOME_DIR" >&2
    exit 1
fi

BLOCK=$(cat)

[[ ! -f "$BASHRC" ]] && touch "$BASHRC"

TMP_FILE=$(mktemp)
trap 'rm -f "$TMP_FILE"' EXIT

awk -v begin="$BEGIN" -v end="$END" '
    $0 == begin { skip=1; next }
    $0 == end { skip=0; next }
    !skip { print }
' "$BASHRC" > "$TMP_FILE"

if [[ -n "$BLOCK" ]]; then
    printf '\n%s\n%s\n%s\n' "$BEGIN" "$BLOCK" "$END" >> "$TMP_FILE"
fi

cat "$TMP_FILE" > "$BASHRC"
chown "$USERNAME:$USERNAME" "$BASHRC"
chmod 644 "$BASHRC"

echo "OK"

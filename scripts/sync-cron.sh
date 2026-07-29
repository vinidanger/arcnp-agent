#!/bin/bash
# Uso: sync-cron.sh <username>
#
# Lê pelo STDIN o conteúdo completo já formatado (uma linha de cron
# por job, cada uma já validada campo a campo pela Action em PHP) e
# regrava /etc/cron.d/arcnp-<username> por inteiro — reescrita
# completa idempotente, igual o padrão já usado pra ACL/config em
# outros scripts, nunca edição incremental. Confere de novo aqui
# (defesa em profundidade) que cada linha tem o username certo no
# campo de usuário antes de gravar num arquivo que o cron do sistema
# roda como root.
set -euo pipefail

USERNAME="${1:-}"

if [[ ! "$USERNAME" =~ ^[a-z][a-z0-9]{2,31}$ ]]; then
    echo "Username inválido: $USERNAME" >&2
    exit 1
fi

CRON_FILE="/etc/cron.d/arcnp-$USERNAME"
TMP_FILE=$(mktemp)
trap 'rm -f "$TMP_FILE"' EXIT

cat > "$TMP_FILE"

while IFS= read -r LINE; do
    [[ -z "$LINE" ]] && continue
    if [[ ! "$LINE" =~ ^([^[:space:]]+[[:space:]]+){5}$USERNAME[[:space:]] ]]; then
        echo "Linha de cron inválida: $LINE" >&2
        exit 1
    fi
done < "$TMP_FILE"

echo "" >> "$TMP_FILE"
mv "$TMP_FILE" "$CRON_FILE"
chown root:root "$CRON_FILE"
chmod 644 "$CRON_FILE"

echo "OK"

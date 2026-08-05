#!/bin/bash
# Uso:
#   manage-php-fpm-service.sh <unit> apply   (conteúdo do .service via STDIN)
#   manage-php-fpm-service.sh <unit> reload
#   manage-php-fpm-service.sh <unit> remove
#   manage-php-fpm-service.sh <unit> stop
#   manage-php-fpm-service.sh <unit> start
#
# Unit systemd DEDICADO por GRUPO (versão de PHP + assinatura das
# zend_extensions em uso, ver PhpFpmPool::processGroupKey() no Agent —
# uma conta pode ter mais de um processo, um por grupo distinto entre
# os domínios dela) — precisa de root pra escrever em
# /etc/systemd/system/ e chamar daemon-reload/enable/disable, mesmo
# raciocínio do manage-app.sh (unit por app Node/Python). "apply" serve
# tanto pra criar quanto pra atualizar (troca de versão de PHP muda o
# ExecStart) — sempre idempotente: escreve + habilita + reinicia, nunca
# assume estado anterior.
set -euo pipefail

UNIT="${1:-}"
OPERATION="${2:-}"

# Formato: arcnp-php-{username}-{versão sem ponto}[-z{hash8}].service
# ex.: arcnp-php-testehos-83.service, arcnp-php-testehos-83-za1b2c3d4.service
if [[ ! "$UNIT" =~ ^arcnp-php-[a-z][a-z0-9]{2,31}-[0-9]+(-z[0-9a-f]{8})?\.service$ ]]; then
    echo "Nome de unit inválido: $UNIT" >&2
    exit 1
fi

UNIT_PATH="/etc/systemd/system/$UNIT"

case "$OPERATION" in
    apply)
        TMP_FILE=$(mktemp)
        trap 'rm -f "$TMP_FILE"' EXIT
        cat > "$TMP_FILE"

        mv "$TMP_FILE" "$UNIT_PATH"
        chown root:root "$UNIT_PATH"
        chmod 0644 "$UNIT_PATH"

        systemctl daemon-reload
        systemctl enable --now "$UNIT"
        systemctl restart "$UNIT"
        ;;
    reload)
        systemctl reload "$UNIT"
        ;;
    remove)
        systemctl disable --now "$UNIT" 2>/dev/null || true
        rm -f "$UNIT_PATH"
        systemctl daemon-reload
        ;;
    stop)
        systemctl stop "$UNIT" 2>/dev/null || true
        ;;
    start)
        systemctl start "$UNIT"
        ;;
    *)
        echo "Operação desconhecida: $OPERATION" >&2
        exit 1
        ;;
esac

echo "OK"

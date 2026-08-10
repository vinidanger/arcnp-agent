#!/bin/bash
# Uso:
#   tail-log.sh nginx <domínio> <access|error> <linhas>
#   tail-log.sh mail <linhas> [busca]
#   tail-log.sh access-full <domínio>
#
# Só leitura, nunca muta nada. O domínio/caminho NUNCA vem pronto do
# Painel — só o que der pra montar aqui dentro a partir de um domínio
# já validado (regex abaixo, defesa em profundidade em cima da
# validação que a Action já faz em DomainName::validate).
set -euo pipefail

OPERATION="${1:-}"

case "$OPERATION" in
    nginx)
        DOMAIN="${2:-}"
        TYPE="${3:-}"
        LINES="${4:-200}"

        if [[ ! "$DOMAIN" =~ ^[a-zA-Z0-9.-]+$ ]]; then
            echo "Domínio inválido: $DOMAIN" >&2
            exit 1
        fi

        case "$TYPE" in
            access|error) ;;
            *)
                echo "Tipo de log inválido: $TYPE" >&2
                exit 1
                ;;
        esac

        FILE="/var/log/nginx/${DOMAIN}-${TYPE}.log"
        [[ -f "$FILE" ]] || exit 0

        tail -n "$LINES" "$FILE"
        ;;
    access-full)
        DOMAIN="${2:-}"

        if [[ ! "$DOMAIN" =~ ^[a-zA-Z0-9.-]+$ ]]; then
            echo "Domínio inválido: $DOMAIN" >&2
            exit 1
        fi

        FILE="/var/log/nginx/${DOMAIN}-access.log"
        [[ -f "$FILE" ]] || exit 0

        cat "$FILE"
        ;;
    mail)
        LINES="${2:-200}"
        SEARCH="${3:-}"

        FILE="/var/log/maillog"
        [[ -f "$FILE" ]] || exit 0

        if [[ -n "$SEARCH" ]]; then
            # Só olha as últimas 5000 linhas antes de filtrar — log de
            # e-mail pode ser grande, isso não é uma busca full-text.
            tail -n 5000 "$FILE" | grep -F -- "$SEARCH" | tail -n "$LINES"
        else
            tail -n "$LINES" "$FILE"
        fi
        ;;
    *)
        echo "Operação desconhecida: $OPERATION" >&2
        exit 1
        ;;
esac

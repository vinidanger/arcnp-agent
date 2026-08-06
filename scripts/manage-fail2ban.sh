#!/bin/bash
# Uso: manage-fail2ban.sh <list|unban> [jail] [ip]
#
# list: pra cada jail conhecido, roda "fail2ban-client status <jail>" e
# imprime uma linha "<jail>\t<ip>" por IP banido (saída fácil de
# parsear no lado PHP, sem depender de JSON do fail2ban-client, que
# varia de formato entre versões).
#
# unban: remove o ban de um IP específico num jail específico.
#
# Lista de jails fechada (mesmo espírito de qualquer whitelist deste
# projeto) — nunca aceita nome de jail livre vindo de fora.
set -euo pipefail

OPERATION="${1:-}"

KNOWN_JAILS=("sshd" "vsftpd")

jail_is_known() {
    local jail="$1"
    for known in "${KNOWN_JAILS[@]}"; do
        [[ "$jail" == "$known" ]] && return 0
    done
    return 1
}

case "$OPERATION" in
    list)
        for jail in "${KNOWN_JAILS[@]}"; do
            # Jail pode não estar configurado/ativo (ex.: vsftpd não
            # instalado nesse servidor) — ignora silenciosamente em vez
            # de abortar a listagem inteira.
            STATUS=$(fail2ban-client status "$jail" 2>/dev/null) || continue

            BANNED_LINE=$(echo "$STATUS" | grep -i "Banned IP list:" || true)
            [[ -z "$BANNED_LINE" ]] && continue

            IPS=$(echo "$BANNED_LINE" | sed -E 's/.*Banned IP list:[[:space:]]*//')
            for ip in $IPS; do
                printf '%s\t%s\n' "$jail" "$ip"
            done
        done
        ;;
    unban)
        JAIL="${2:-}"
        IP="${3:-}"

        if ! jail_is_known "$JAIL"; then
            echo "Jail inválido: $JAIL" >&2
            exit 1
        fi

        if [[ ! "$IP" =~ ^[0-9a-fA-F:.]+$ ]]; then
            echo "IP inválido: $IP" >&2
            exit 1
        fi

        fail2ban-client set "$JAIL" unbanip "$IP"
        ;;
    *)
        echo "Operação inválida: $OPERATION" >&2
        exit 1
        ;;
esac

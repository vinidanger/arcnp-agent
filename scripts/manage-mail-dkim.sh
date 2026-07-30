#!/bin/bash
# Uso:
#   manage-mail-dkim.sh generate-key <domínio>   (imprime o valor do TXT no stdout, idempotente)
#   manage-mail-dkim.sh sync-tables              (conteúdo via STDIN, ver App\Support\OpenDkimTables::bundle)
#   manage-mail-dkim.sh delete-key <domínio>
set -euo pipefail

OPERATION="${1:-}"

validate_domain() {
    if [[ ! "$1" =~ ^[a-z0-9]([a-z0-9.-]{0,251}[a-z0-9])?$ ]]; then
        echo "Domínio inválido: $1" >&2
        exit 1
    fi
}

case "$OPERATION" in
    generate-key)
        DOMAIN="${2:-}"
        validate_domain "$DOMAIN"

        KEYDIR="/etc/opendkim/keys/$DOMAIN"

        if [[ ! -f "$KEYDIR/mail.private" ]]; then
            mkdir -p "$KEYDIR"
            opendkim-genkey -b 2048 -d "$DOMAIN" -s mail -D "$KEYDIR"
            chown -R opendkim:opendkim "$KEYDIR"
            chmod 600 "$KEYDIR/mail.private"
        fi

        # mail.txt vem quebrado em várias strings entre parênteses (formato
        # de zona DNS multi-linha) — junta tudo numa linha só pro Painel
        # guardar/exibir como um único valor de registro TXT.
        grep -o '"[^"]*"' "$KEYDIR/mail.txt" | tr -d '"\n'
        echo
        ;;
    sync-tables)
        TMP="$(mktemp -d)"
        trap 'rm -rf "$TMP"' EXIT

        awk -v dir="$TMP" '
            /^===.*===$/ {
                name = substr($0, 4, length($0) - 6)
                file = dir "/" name
                next
            }
            file { print > file }
        '

        for section in KEYTABLE SIGNINGTABLE; do
            if [[ ! -f "$TMP/$section" ]]; then
                echo "Bundle inválido: faltando seção $section" >&2
                exit 1
            fi
        done

        install -m 0644 -o root -g opendkim "$TMP/KEYTABLE" /etc/opendkim/KeyTable
        install -m 0644 -o root -g opendkim "$TMP/SIGNINGTABLE" /etc/opendkim/SigningTable

        systemctl restart opendkim

        echo "OK"
        ;;
    delete-key)
        DOMAIN="${2:-}"
        validate_domain "$DOMAIN"

        rm -rf "/etc/opendkim/keys/$DOMAIN"

        echo "OK"
        ;;
    *)
        echo "Operação desconhecida: $OPERATION" >&2
        exit 1
        ;;
esac

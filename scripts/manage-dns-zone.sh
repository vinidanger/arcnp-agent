#!/bin/bash
# Uso: manage-dns-zone.sh <write-zone|reload-zone|sync-zones-conf|delete-zone> [domain]
#
# write-zone <domain>: lê o zone file pelo STDIN, valida com
#   named-checkzone ANTES de aplicar, grava em
#   /etc/named/zones/<domain>.zone. NÃO recarrega — pra zona nova, o
#   arquivo precisa existir antes do zones.conf referenciar ela (senão
#   o reload falha reclamando de arquivo ausente); pra zona já
#   existente, quem chama roda reload-zone depois.
#
# reload-zone <domain>: só `rndc reload <domain>` — usado depois de
#   write-zone quando é edição de registro numa zona que já existe
#   (recarrega só ela, mais leve que tudo).
#
# sync-zones-conf: lê pelo STDIN o conteúdo completo já pronto do
#   arquivo de inclusão de zonas (uma stanza "zone {...};" por domínio
#   ativo nesse servidor — o Painel é sempre a fonte da verdade,
#   sempre reenvia a lista inteira, nunca edição incremental), valida
#   com named-checkconf antes de aplicar, grava em
#   /etc/named/zones.conf, recarrega o named inteiro (`rndc reload`,
#   sem argumento). Usado em criação/exclusão de zona, quando a LISTA
#   muda (não precisa disso só por causa de registro editado).
#
# delete-zone <domain>: remove o arquivo da zona — só depois que
#   sync-zones-conf já tirou ela do named.conf e recarregou, senão o
#   named ainda espera o arquivo existir.
set -euo pipefail

OPERATION="${1:-}"
DOMAIN="${2:-}"

ZONES_DIR="/etc/named/zones"
ZONES_CONF="/etc/named/zones.conf"

validate_domain() {
    if [[ ! "$1" =~ ^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$ ]]; then
        echo "Domínio inválido: $1" >&2
        exit 1
    fi
}

case "$OPERATION" in
    write-zone)
        validate_domain "$DOMAIN"
        TMP_FILE=$(mktemp)
        trap 'rm -f "$TMP_FILE"' EXIT
        cat > "$TMP_FILE"

        named-checkzone "$DOMAIN" "$TMP_FILE"

        mkdir -p "$ZONES_DIR"
        install -m 0640 -o named -g named "$TMP_FILE" "$ZONES_DIR/$DOMAIN.zone"
        ;;
    reload-zone)
        validate_domain "$DOMAIN"
        rndc reload "$DOMAIN"
        ;;
    sync-zones-conf)
        TMP_FILE=$(mktemp)
        trap 'rm -f "$TMP_FILE"' EXIT
        cat > "$TMP_FILE"

        named-checkconf "$TMP_FILE"

        mkdir -p "$(dirname "$ZONES_CONF")"
        install -m 0640 -o named -g named "$TMP_FILE" "$ZONES_CONF"

        named-checkconf
        rndc reload
        ;;
    delete-zone)
        validate_domain "$DOMAIN"
        rm -f "$ZONES_DIR/$DOMAIN.zone"
        ;;
    *)
        echo "Operação desconhecida: $OPERATION" >&2
        exit 1
        ;;
esac

echo "OK"

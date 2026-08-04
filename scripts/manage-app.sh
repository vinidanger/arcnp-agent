#!/bin/bash
# Uso:
#   manage-app.sh <unit> create   (conteúdo do .service via STDIN)
#   manage-app.sh <unit> remove
#   manage-app.sh <unit> restart
#
# Unit systemd por app (Node.js/Python) — precisa de root pra escrever
# em /etc/systemd/system/ e chamar daemon-reload/enable/disable, coisa
# que o banco de usuários FTP não precisa (só é lido pelo PAM) mas o
# vsftpd user_conf também precisa (mesmo raciocínio da seção de FTP:
# systemd não tem a mesma checagem de dono do vsftpd, mas escrever
# fora de /etc/systemd/system/ simplesmente não funciona sem root).
set -euo pipefail

UNIT="${1:-}"
OPERATION="${2:-}"

if [[ ! "$UNIT" =~ ^arcnp-app-[0-9]+\.service$ ]]; then
    echo "Nome de unit inválido: $UNIT" >&2
    exit 1
fi

UNIT_PATH="/etc/systemd/system/$UNIT"

case "$OPERATION" in
    create)
        TMP_FILE=$(mktemp)
        trap 'rm -f "$TMP_FILE"' EXIT
        cat > "$TMP_FILE"

        mv "$TMP_FILE" "$UNIT_PATH"
        chown root:root "$UNIT_PATH"
        chmod 0644 "$UNIT_PATH"

        systemctl daemon-reload
        systemctl enable --now "$UNIT"
        ;;
    remove)
        systemctl disable --now "$UNIT" 2>/dev/null || true
        rm -f "$UNIT_PATH"
        systemctl daemon-reload
        ;;
    restart)
        systemctl restart "$UNIT"
        ;;
    *)
        echo "Operação desconhecida: $OPERATION" >&2
        exit 1
        ;;
esac

echo "OK"

#!/bin/bash
# Uso: toggle-php-extension.sh <ini_dir> <filename> <enable|disable>
#
# ini_dir precisa ser um dos diretórios conhecidos (a lista abaixo tem
# que bater com os "ini_dir" de config/provisioning.php do Agent) —
# evita esse script virar um "mover qualquer arquivo" genérico.
# filename só aceita o formato de nome de arquivo .ini do PHP (sem
# barra, sem "..", sem espaço).
set -euo pipefail

INI_DIR="${1:-}"
FILENAME="${2:-}"
ACTION="${3:-}"

case "$INI_DIR" in
    /etc/php.d|/etc/opt/remi/php81/php.d|/etc/opt/remi/php82/php.d|/etc/opt/remi/php84/php.d)
        ;;
    *)
        echo "Diretório de ini não permitido: $INI_DIR" >&2
        exit 1
        ;;
esac

if [[ ! "$FILENAME" =~ ^[a-zA-Z0-9_.-]+\.ini$ ]]; then
    echo "Nome de arquivo inválido: $FILENAME" >&2
    exit 1
fi

case "$ACTION" in
    disable)
        [[ ! -f "$INI_DIR/$FILENAME" ]] && { echo "Não encontrado: $FILENAME" >&2; exit 1; }
        mv "$INI_DIR/$FILENAME" "$INI_DIR/$FILENAME.disabled"
        ;;
    enable)
        [[ ! -f "$INI_DIR/$FILENAME.disabled" ]] && { echo "Não encontrado (desabilitado): $FILENAME" >&2; exit 1; }
        mv "$INI_DIR/$FILENAME.disabled" "$INI_DIR/$FILENAME"
        ;;
    *)
        echo "Ação desconhecida: $ACTION" >&2
        exit 1
        ;;
esac

echo "OK"

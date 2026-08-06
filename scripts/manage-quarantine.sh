#!/bin/bash
# Uso: manage-quarantine.sh <username> <quarantine|restore> <rel_path> <quarantine_filename>
#
# quarantine: move {home}/{rel_path} pra {home}/.quarantine/{quarantine_filename}
# restore: move {home}/.quarantine/{quarantine_filename} de volta pra {home}/{rel_path}
#
# Ancorado no home INTEIRO (não só public_html) — arquivo malicioso
# pode estar em qualquer lugar da conta (Maildir, backup local, etc.),
# diferente do manage-file.sh (gerenciador de arquivos, sempre dentro
# de public_html). rel_path/quarantine_filename já validados pelo PHP
# antes de chegar aqui (FtpChrootPath::resolveExisting / regex
# fechado), revalidado aqui de novo por defesa em profundidade, mesmo
# padrão do resto do Agent.
set -euo pipefail

USERNAME="${1:-}"
OPERATION="${2:-}"
REL_PATH="${3:-}"
QUARANTINE_FILENAME="${4:-}"

if [[ ! "$USERNAME" =~ ^[a-z][a-z0-9]{2,31}$ ]]; then
    echo "Username inválido: $USERNAME" >&2
    exit 1
fi

if [[ ! "$QUARANTINE_FILENAME" =~ ^[a-zA-Z0-9._-]+$ ]]; then
    echo "Nome de quarentena inválido: $QUARANTINE_FILENAME" >&2
    exit 1
fi

HOME_DIR="/home/$USERNAME"

if [[ ! -d "$HOME_DIR" ]]; then
    echo "Home não existe: $HOME_DIR" >&2
    exit 1
fi

HOME_REAL=$(realpath "$HOME_DIR")
QUARANTINE_DIR="$HOME_REAL/.quarantine"

resolve() {
    local rel="$1"
    rel="${rel#/}"

    if [[ -z "$rel" ]] || [[ "$rel" =~ (^|/)\.\.(/|$) ]]; then
        echo "Caminho inválido: $rel" >&2
        exit 1
    fi

    local real
    real=$(realpath -m "$HOME_REAL/$rel")

    if [[ "$real" != "$HOME_REAL" && "$real" != "$HOME_REAL"/* ]]; then
        echo "Caminho fora da raiz: $rel" >&2
        exit 1
    fi

    echo "$real"
}

case "$OPERATION" in
    quarantine)
        SRC=$(resolve "$REL_PATH")

        if [[ ! -f "$SRC" ]]; then
            echo "Arquivo não encontrado: $SRC" >&2
            exit 1
        fi

        mkdir -p "$QUARANTINE_DIR"
        chown "$USERNAME:$USERNAME" "$QUARANTINE_DIR"
        chmod 700 "$QUARANTINE_DIR"

        mv "$SRC" "$QUARANTINE_DIR/$QUARANTINE_FILENAME"
        chown "$USERNAME:$USERNAME" "$QUARANTINE_DIR/$QUARANTINE_FILENAME"
        ;;
    restore)
        SRC="$QUARANTINE_DIR/$QUARANTINE_FILENAME"
        DEST=$(resolve "$REL_PATH")

        if [[ ! -f "$SRC" ]]; then
            echo "Arquivo em quarentena não encontrado: $SRC" >&2
            exit 1
        fi

        DEST_PARENT=$(dirname "$DEST")
        if [[ ! -d "$DEST_PARENT" ]]; then
            echo "Diretório de destino não existe: $DEST_PARENT" >&2
            exit 1
        fi

        mv "$SRC" "$DEST"
        chown "$USERNAME:$USERNAME" "$DEST"
        ;;
    *)
        echo "Operação inválida: $OPERATION" >&2
        exit 1
        ;;
esac

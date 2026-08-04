#!/bin/bash
# Uso:
#   manage-archive.sh <username> <root> compress <output_zip_relpath>   (STDIN = lista de caminhos relativos, um por linha)
#   manage-archive.sh <username> <root> extract <zip_relpath> <dest_relpath>
#
# Mesma raiz/resolução de caminho do manage-file.sh (root vazio =
# public_html, senão domains/{root}/public_html) — ver esse script pra
# mais contexto. Roda como root porque precisa ajustar posse de volta
# pro dono da conta depois.
set -euo pipefail

USERNAME="${1:-}"
ROOT="${2:-}"
OPERATION="${3:-}"
REL_PATH="${4:-}"
REL_PATH_2="${5:-}"

if [[ ! "$USERNAME" =~ ^[a-z][a-z0-9]{2,31}$ ]]; then
    echo "Username inválido: $USERNAME" >&2
    exit 1
fi

HOME_DIR="/home/$USERNAME"

if [[ -z "$ROOT" ]]; then
    BASE="$HOME_DIR/public_html"
else
    if [[ ! "$ROOT" =~ ^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$ ]]; then
        echo "Raiz inválida: $ROOT" >&2
        exit 1
    fi
    BASE="$HOME_DIR/domains/$ROOT/public_html"
fi

[[ ! -d "$BASE" ]] && { echo "Raiz não existe: $BASE" >&2; exit 1; }

BASE_REAL=$(realpath "$BASE")

resolve() {
    local rel="$1"
    rel="${rel#/}"

    if [[ "$rel" =~ (^|/)\.\.(/|$) ]]; then
        echo "Caminho inválido: $rel" >&2
        exit 1
    fi

    local real
    real=$(realpath -m "$BASE_REAL/$rel")

    if [[ "$real" != "$BASE_REAL" && "$real" != "$BASE_REAL"/* ]]; then
        echo "Caminho fora da raiz: $rel" >&2
        exit 1
    fi

    echo "$real"
}

case "$OPERATION" in
    compress)
        [[ -z "$REL_PATH" ]] && { echo "Caminho de saída obrigatório" >&2; exit 1; }
        TARGET=$(resolve "$REL_PATH")
        [[ -e "$TARGET" ]] && { echo "Já existe: $REL_PATH" >&2; exit 1; }
        [[ ! -d "$(dirname "$TARGET")" ]] && { echo "Diretório pai não existe" >&2; exit 1; }

        # Lê a lista (STDIN, um caminho relativo por linha), valida CADA
        # um contra a raiz, e só então monta o zip relativo à raiz —
        # "zip -@" lê os nomes prontos do stdin, então valida primeiro
        # num array em vez de repassar direto.
        TMP_LIST="$(mktemp)"
        trap 'rm -f "$TMP_LIST"' EXIT

        while IFS= read -r line; do
            [[ -z "$line" ]] && continue
            RESOLVED=$(resolve "$line")
            [[ ! -e "$RESOLVED" ]] && { echo "Não encontrado: $line" >&2; exit 1; }
            echo "$line" >> "$TMP_LIST"
        done

        [[ ! -s "$TMP_LIST" ]] && { echo "Nenhum item pra compactar" >&2; exit 1; }

        (cd "$BASE_REAL" && zip -r -q "$TARGET" -@ < "$TMP_LIST")
        chown "$USERNAME:$USERNAME" "$TARGET"
        ;;
    extract)
        # REL_PATH_2 vazio é válido (extrair na raiz) — resolve() já
        # trata "" como o próprio BASE_REAL. Só o zip em si (REL_PATH)
        # precisa mesmo ser não-vazio.
        [[ -z "$REL_PATH" ]] && { echo "Caminho do zip obrigatório" >&2; exit 1; }
        ZIP_PATH=$(resolve "$REL_PATH")
        DEST_PATH=$(resolve "$REL_PATH_2")
        [[ ! -f "$ZIP_PATH" ]] && { echo "Arquivo não encontrado: $REL_PATH" >&2; exit 1; }
        [[ ! -d "$DEST_PATH" ]] && { echo "Destino não existe: $REL_PATH_2" >&2; exit 1; }

        unzip -o -q "$ZIP_PATH" -d "$DEST_PATH"
        chown -R "$USERNAME:$USERNAME" "$DEST_PATH"
        ;;
    *)
        echo "Operação desconhecida: $OPERATION" >&2
        exit 1
        ;;
esac

echo "OK"

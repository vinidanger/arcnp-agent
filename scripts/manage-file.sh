#!/bin/bash
# Uso: manage-file.sh <username> <write|mkdir|touch|delete|rename> <path> [path_2]
#
# Único ponto que MUTA arquivos dentro de public_html — listar/ler não
# passam por aqui (o Agent já tem ACL de leitura, ver create-hosting-user.sh).
# Roda como root (sudoers) porque o Agent (arcnpagent) só tem leitura;
# criar/escrever exige poder ajustar a posse de volta pro dono da conta
# depois. "write" lê o conteúdo novo do STDIN, nunca de argv (arquivo
# pode ser grande, e argv tem limite de tamanho do SO).
#
# Por simplicidade (e porque a UI sempre opera dentro da pasta que já
# está navegando), NÃO cria diretórios intermediários — o diretório pai
# do alvo precisa já existir.
set -euo pipefail

USERNAME="${1:-}"
OPERATION="${2:-}"
REL_PATH="${3:-}"
REL_PATH_2="${4:-}"

if [[ ! "$USERNAME" =~ ^[a-z][a-z0-9]{2,31}$ ]]; then
    echo "Username inválido: $USERNAME" >&2
    exit 1
fi

BASE="/home/$USERNAME/public_html"

if [[ ! -d "$BASE" ]]; then
    echo "public_html não existe: $BASE" >&2
    exit 1
fi

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
        echo "Caminho fora de public_html: $rel" >&2
        exit 1
    fi

    echo "$real"
}

case "$OPERATION" in
    write)
        [[ -z "$REL_PATH" ]] && { echo "Caminho obrigatório" >&2; exit 1; }
        TARGET=$(resolve "$REL_PATH")
        [[ -d "$TARGET" ]] && { echo "É um diretório: $REL_PATH" >&2; exit 1; }
        [[ ! -d "$(dirname "$TARGET")" ]] && { echo "Diretório pai não existe" >&2; exit 1; }
        cat > "$TARGET"
        chown "$USERNAME:$USERNAME" "$TARGET"
        ;;
    mkdir)
        [[ -z "$REL_PATH" ]] && { echo "Caminho obrigatório" >&2; exit 1; }
        TARGET=$(resolve "$REL_PATH")
        [[ -e "$TARGET" ]] && { echo "Já existe: $REL_PATH" >&2; exit 1; }
        [[ ! -d "$(dirname "$TARGET")" ]] && { echo "Diretório pai não existe" >&2; exit 1; }
        mkdir "$TARGET"
        chown "$USERNAME:$USERNAME" "$TARGET"
        ;;
    touch)
        [[ -z "$REL_PATH" ]] && { echo "Caminho obrigatório" >&2; exit 1; }
        TARGET=$(resolve "$REL_PATH")
        [[ -e "$TARGET" ]] && { echo "Já existe: $REL_PATH" >&2; exit 1; }
        [[ ! -d "$(dirname "$TARGET")" ]] && { echo "Diretório pai não existe" >&2; exit 1; }
        : > "$TARGET"
        chown "$USERNAME:$USERNAME" "$TARGET"
        ;;
    delete)
        [[ -z "$REL_PATH" ]] && { echo "Caminho obrigatório" >&2; exit 1; }
        TARGET=$(resolve "$REL_PATH")
        [[ "$TARGET" == "$BASE_REAL" ]] && { echo "Não pode remover a raiz do public_html" >&2; exit 1; }
        [[ ! -e "$TARGET" ]] && { echo "Não encontrado: $REL_PATH" >&2; exit 1; }
        rm -rf "$TARGET"
        ;;
    rename)
        [[ -z "$REL_PATH" || -z "$REL_PATH_2" ]] && { echo "Caminhos obrigatórios" >&2; exit 1; }
        FROM=$(resolve "$REL_PATH")
        TO=$(resolve "$REL_PATH_2")
        [[ ! -e "$FROM" ]] && { echo "Não encontrado: $REL_PATH" >&2; exit 1; }
        [[ -e "$TO" ]] && { echo "Já existe: $REL_PATH_2" >&2; exit 1; }
        [[ ! -d "$(dirname "$TO")" ]] && { echo "Diretório pai do destino não existe" >&2; exit 1; }
        mv "$FROM" "$TO"
        chown -R "$USERNAME:$USERNAME" "$TO"
        ;;
    *)
        echo "Operação desconhecida: $OPERATION" >&2
        exit 1
        ;;
esac

echo "OK"

#!/bin/bash
# Uso: optimize-images.sh <username>
#
# Gera .webp/.avif ao lado de cada .jpg/.jpeg/.png sob o home INTEIRO da
# conta (mesmo espírito de manage-quarantine.sh — imagem pode estar em
# qualquer lugar da conta, não só public_html; .quarantine é excluído
# de propósito). Só (re)converte se o derivado ainda não existir ou se
# a origem for mais nova — evita reprocessar tudo a cada rodada.
#
# Roda como root (sudo) porque ESCREVE arquivo novo dentro do home do
# cliente — diferente do scanner de malware (clamscan), que só lê.
# chown de volta pro dono a cada arquivo gerado, mesmo padrão do resto
# dos scripts que escrevem em home de conta.
set -euo pipefail

USERNAME="${1:-}"

if [[ ! "$USERNAME" =~ ^[a-z][a-z0-9]{2,31}$ ]]; then
    echo "Username inválido: $USERNAME" >&2
    exit 1
fi

HOME_DIR="/home/$USERNAME"

if [[ ! -d "$HOME_DIR" ]]; then
    echo "Home não existe: $HOME_DIR" >&2
    exit 1
fi

HOME_REAL=$(realpath "$HOME_DIR")

HAVE_CWEBP=0
HAVE_AVIFENC=0
command -v cwebp >/dev/null 2>&1 && HAVE_CWEBP=1
command -v avifenc >/dev/null 2>&1 && HAVE_AVIFENC=1

PROCESSED=0
CONVERTED=0
SKIPPED=0

while IFS= read -r -d '' file; do
    PROCESSED=$((PROCESSED + 1))
    made_one=0

    webp="${file}.webp"
    if [[ "$HAVE_CWEBP" -eq 1 ]] && { [[ ! -f "$webp" ]] || [[ "$file" -nt "$webp" ]]; }; then
        if cwebp -quiet -q 80 "$file" -o "$webp" 2>/dev/null; then
            chown "$USERNAME:$USERNAME" "$webp"
            made_one=1
        fi
    fi

    avif="${file}.avif"
    if [[ "$HAVE_AVIFENC" -eq 1 ]] && { [[ ! -f "$avif" ]] || [[ "$file" -nt "$avif" ]]; }; then
        if avifenc -q 80 "$file" "$avif" >/dev/null 2>&1; then
            chown "$USERNAME:$USERNAME" "$avif"
            made_one=1
        fi
    fi

    if [[ "$made_one" -eq 1 ]]; then
        CONVERTED=$((CONVERTED + 1))
    else
        SKIPPED=$((SKIPPED + 1))
    fi
done < <(find "$HOME_REAL" -path "$HOME_REAL/.quarantine" -prune -o -type f \( -iname '*.jpg' -o -iname '*.jpeg' -o -iname '*.png' \) -print0)

echo "PROCESSED:$PROCESSED"
echo "CONVERTED:$CONVERTED"
echo "SKIPPED:$SKIPPED"

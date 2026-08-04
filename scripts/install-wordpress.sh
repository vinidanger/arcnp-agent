#!/bin/bash
# Uso: install-wordpress.sh <username> <domain> <root> <dest_relpath> <download_url> <site_url> <db_name> <db_username> <db_password> <admin_user> <admin_password> <admin_email> <site_title>
# STDIN = conteúdo pronto do wp-config.php (o PHP já monta a string
# toda, esse script só grava).
#
# root vazio = public_html do domínio principal da conta (mesma
# convenção de manage-file.sh/manage-archive.sh); senão é
# domains/{root}/public_html (domínio adicional fora de public_html).
# domain é sempre o hostname real, mesmo quando root está vazio.
# site_url já vem pronto do PHP (esquema http/https + domínio + pasta,
# se tiver) — é o que vai pro --url do wp-cli, e fica GRAVADO pra
# sempre nas opções siteurl/home do WordPress, então tem que estar
# certo já na primeira instalação (não dá pra "corrigir depois" só
# acessando outra URL). Roda como root (mesmo motivo dos outros
# scripts de arquivo: precisa poder ajustar posse de volta pro dono da
# conta no final) e faz chown -R no destino inteiro ao terminar.
set -euo pipefail

USERNAME="${1:-}"
DOMAIN="${2:-}"
ROOT="${3:-}"
DEST_RELPATH="${4:-}"
DOWNLOAD_URL="${5:-}"
SITE_URL="${6:-}"
DB_NAME="${7:-}"
DB_USERNAME="${8:-}"
DB_PASSWORD="${9:-}"
ADMIN_USER="${10:-}"
ADMIN_PASSWORD="${11:-}"
ADMIN_EMAIL="${12:-}"
SITE_TITLE="${13:-}"

if [[ ! "$USERNAME" =~ ^[a-z][a-z0-9]{2,31}$ ]]; then
    echo "Username inválido: $USERNAME" >&2
    exit 1
fi

if [[ -z "$DOMAIN" ]]; then
    echo "Domínio obrigatório" >&2
    exit 1
fi

if [[ -z "$SITE_URL" ]]; then
    echo "URL do site obrigatória" >&2
    exit 1
fi

# Defesa em profundidade — o Painel só manda a URL fixa do catálogo,
# mas revalida aqui mesmo assim (nunca confiar só na validação de quem
# chamou, mesmo padrão do resto do Agent).
if [[ ! "$DOWNLOAD_URL" =~ ^https://[a-z0-9.-]*wordpress\.org/ ]]; then
    echo "URL de download não permitida: $DOWNLOAD_URL" >&2
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

REL="${DEST_RELPATH#/}"
if [[ "$REL" =~ (^|/)\.\.(/|$) ]]; then
    echo "Caminho inválido: $DEST_RELPATH" >&2
    exit 1
fi

DEST=$(realpath -m "$BASE_REAL/$REL")

if [[ "$DEST" != "$BASE_REAL" && "$DEST" != "$BASE_REAL"/* ]]; then
    echo "Caminho fora da raiz: $DEST_RELPATH" >&2
    exit 1
fi

mkdir -p "$DEST"

if [[ -f "$DEST/wp-config.php" ]]; then
    echo "Já existe uma instalação em: $DEST_RELPATH" >&2
    exit 1
fi

TMPDIR_INSTALL=$(mktemp -d)
trap 'rm -rf "$TMPDIR_INSTALL"' EXIT

curl -fsSL --max-time 120 "$DOWNLOAD_URL" -o "$TMPDIR_INSTALL/wp.zip"

# Assinatura de zip (PK) — rejeita página de erro HTML disfarçada de
# zip (ex.: um 404/redirecionamento inesperado do CDN).
SIGNATURE=$(head -c 2 "$TMPDIR_INSTALL/wp.zip")
if [[ "$SIGNATURE" != "PK" ]]; then
    echo "Download não parece ser um zip válido" >&2
    exit 1
fi

mkdir -p "$TMPDIR_INSTALL/extracted"
unzip -q "$TMPDIR_INSTALL/wp.zip" -d "$TMPDIR_INSTALL/extracted"

if [[ ! -d "$TMPDIR_INSTALL/extracted/wordpress" ]]; then
    echo "Estrutura do zip inesperada (pasta wordpress/ não encontrada)" >&2
    exit 1
fi

shopt -s dotglob
mv "$TMPDIR_INSTALL/extracted/wordpress/"* "$DEST/"
shopt -u dotglob

cat > "$DEST/wp-config.php"

php /usr/local/bin/wp-cli.phar core install \
    --path="$DEST" \
    --url="$SITE_URL" \
    --title="$SITE_TITLE" \
    --admin_user="$ADMIN_USER" \
    --admin_password="$ADMIN_PASSWORD" \
    --admin_email="$ADMIN_EMAIL" \
    --skip-email \
    --allow-root

chown -R "$USERNAME:$USERNAME" "$DEST"

echo "OK"

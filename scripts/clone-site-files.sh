#!/bin/bash
# Uso: clone-site-files.sh <username> <caminho-relativo-origem> <caminho-relativo-destino>
#
# Copia o conteúdo de um diretório dentro do home da conta pra outro
# (ex.: public_html -> domains/staging.dominio.com/public_html) — usado
# pelo clone de staging. Os dois caminhos vêm resolvidos pela Action
# (App\Support\DocumentRoot::resolve()), mas nunca são confiados prontos
# — revalidados aqui contra escapar do home via ../ , mesmo espírito de
# defesa em profundidade do resto dos scripts sudo.
#
# O DESTINO precisa já existir (criado vazio, com as ACLs de
# nginx/arcnpagent já aplicadas, por web.create_addon_domain ANTES
# dessa Action rodar) — esse script só copia conteúdo pra dentro, nunca
# cria o diretório em si.
set -euo pipefail

USERNAME="${1:-}"
SRC_REL="${2:-}"
DEST_REL="${3:-}"

if [[ ! "$USERNAME" =~ ^[a-z][a-z0-9]{2,31}$ ]]; then
    echo "Username inválido: $USERNAME" >&2
    exit 1
fi

HOME_DIR="/home/$USERNAME"

SRC="$(realpath -m "$HOME_DIR/$SRC_REL")"
DEST="$(realpath -m "$HOME_DIR/$DEST_REL")"

case "$SRC" in
    "$HOME_DIR"/*) ;;
    *)
        echo "Caminho de origem fora do home: $SRC_REL" >&2
        exit 1
        ;;
esac

case "$DEST" in
    "$HOME_DIR"/*) ;;
    *)
        echo "Caminho de destino fora do home: $DEST_REL" >&2
        exit 1
        ;;
esac

if [[ ! -d "$SRC" ]]; then
    echo "Origem não existe: $SRC" >&2
    exit 1
fi

if [[ ! -d "$DEST" ]]; then
    echo "Destino não existe (era esperado já ter sido criado antes): $DEST" >&2
    exit 1
fi

cp -a "$SRC"/. "$DEST"/
chown -R "$USERNAME:$USERNAME" "$DEST"

# cp -a preserva atributos da ORIGEM (inclusive ACL, via xattr) — não
# dá pra confiar que isso deixa nginx/arcnpagent com leitura no
# resultado final (foi exatamente o bug real encontrado em produção:
# RewriteWpConfigDatabaseAction, sem sudo, falhava com "Caminho
# inválido." porque não conseguia nem enxergar os arquivos recém-
# copiados). Reaplica a MESMA ACL que create-addon-directory.sh já
# concede na criação do diretório vazio, agora sobre o conteúdo
# copiado — recursiva + default, pra qualquer coisa adicionada depois
# também herdar.
setfacl -R -m u:nginx:rx "$DEST"
setfacl -d -m u:nginx:rx "$DEST"
setfacl -R -m u:arcnpagent:rx "$DEST"
setfacl -d -m u:arcnpagent:rx "$DEST"

echo "OK"

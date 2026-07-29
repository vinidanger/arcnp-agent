#!/bin/bash
# Uso: create-hosting-user.sh <username>
#
# Autorizado via sudoers (deploy/sudoers/arcnp-agent) com argumentos
# livres — a validação do username é feita AQUI, não no sudoers, como
# defesa em profundidade (o Agent em PHP já valida antes de chamar,
# mas o script nunca confia só nisso).
set -euo pipefail

USERNAME="${1:-}"

if [[ ! "$USERNAME" =~ ^[a-z][a-z0-9]{2,31}$ ]]; then
    echo "Username inválido: $USERNAME" >&2
    exit 1
fi

HOME_DIR="/home/$USERNAME"

if id -u "$USERNAME" >/dev/null 2>&1; then
    echo "Usuário já existe: $USERNAME" >&2
    exit 1
fi

useradd -m -d "$HOME_DIR" -s /sbin/nologin "$USERNAME"
mkdir -p "$HOME_DIR/public_html" "$HOME_DIR/logs"
chown -R "$USERNAME:$USERNAME" "$HOME_DIR/public_html" "$HOME_DIR/logs"
chmod 750 "$HOME_DIR" "$HOME_DIR/public_html" "$HOME_DIR/logs"

# O Nginx roda como usuário "nginx", não como o dono do home dir — sem
# isso ele não consegue nem atravessar o diretório pra servir arquivo
# estático nenhum (imagem, CSS, e principalmente o desafio do Let's
# Encrypt em .well-known/acme-challenge), e o try_files cai silenciosamente
# pro PHP em vez de servir o arquivo real. ACL em vez de mudar a
# permissão do home dir inteiro, pra não abrir leitura entre contas.
setfacl -m u:nginx:x "$HOME_DIR"
setfacl -R -m u:nginx:rx "$HOME_DIR/public_html"
setfacl -d -m u:nginx:rx "$HOME_DIR/public_html"

echo "OK"

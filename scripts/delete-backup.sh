#!/bin/bash
# Uso: delete-backup.sh <username> <filename1> [filename2 ...]
#
# Backups pertencem ao usuário da conta (create-backup.sh só concede
# ACL de LEITURA pro arcnpagent, ver esse script) — apagar exige
# escrita no diretório backups/, daí passar por root aqui, mesmo padrão
# dos demais scripts privilegiados.
set -euo pipefail

USERNAME="${1:-}"
shift || true

if [[ ! "$USERNAME" =~ ^[a-z][a-z0-9]{2,31}$ ]]; then
    echo "Username inválido: $USERNAME" >&2
    exit 1
fi

if [[ "$#" -eq 0 ]]; then
    echo "Nenhum arquivo informado" >&2
    exit 1
fi

BACKUP_DIR="/home/$USERNAME/backups"

if [[ ! -d "$BACKUP_DIR" ]]; then
    echo "Diretório de backups não existe: $BACKUP_DIR" >&2
    exit 1
fi

for FILENAME in "$@"; do
    # Sem "/" no charset permitido, ".." sozinho (sem barra) ainda bate
    # no regex — checado à parte, senão "rm -f $BACKUP_DIR/.." apagaria
    # o home inteiro do usuário.
    if [[ ! "$FILENAME" =~ ^[a-zA-Z0-9._-]+$ ]] || [[ "$FILENAME" == *..* ]]; then
        echo "Nome de arquivo inválido: $FILENAME" >&2
        exit 1
    fi

    rm -f "$BACKUP_DIR/$FILENAME"
done

echo "OK"

#!/bin/bash
# Uso: manage-ftp.sh sync-user-configs   (conteúdo via STDIN)
#
# O vsftpd exige que todo arquivo dentro de user_config_dir seja dono
# de root — se não fosse, qualquer processo capaz de escrever ali
# controlaria pra onde um login FTP é redirecionado (local_root) e com
# qual UID (guest_username). Por isso, diferente do banco de usuários
# virtuais (virtual_users.db, que o Agent escreve direto sem sudo — só
# é lido pelo PAM, sem checagem de dono), os arquivos por usuário
# passam por aqui.
#
# STDIN: uma linha por conta, formato "username:linux_username:local_root".
# Estado completo — reescreve tudo, remove o que não estiver na lista
# (mesmo padrão idempotente do resto do painel).
set -euo pipefail

OPERATION="${1:-}"

case "$OPERATION" in
    sync-user-configs)
        DIR="/etc/vsftpd/user_conf"
        mkdir -p "$DIR"

        declare -A KEEP=()

        while IFS=: read -r username linux_username local_root; do
            [[ -z "$username" ]] && continue

            if [[ ! "$username" =~ ^[a-zA-Z0-9._@-]{3,32}$ ]]; then
                echo "Usuário de FTP inválido: $username" >&2
                exit 1
            fi

            if [[ ! "$local_root" =~ ^/home/ ]]; then
                echo "Caminho de FTP inválido: $local_root" >&2
                exit 1
            fi

            FILE="$DIR/$username"
            {
                echo "guest_username=$linux_username"
                echo "local_root=$local_root"
                echo "write_enable=YES"
            } > "$FILE"
            chown root:root "$FILE"
            chmod 0600 "$FILE"
            KEEP["$FILE"]=1
        done

        for existing in "$DIR"/*; do
            [[ -e "$existing" ]] || continue
            if [[ -z "${KEEP[$existing]:-}" ]]; then
                rm -f "$existing"
            fi
        done
        ;;
    *)
        echo "Operação desconhecida: $OPERATION" >&2
        exit 1
        ;;
esac

echo "OK"

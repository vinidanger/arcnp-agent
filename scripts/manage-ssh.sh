#!/bin/bash
# Uso: manage-ssh.sh <username> <set-shell|sync-keys> [enabled|disabled]
#
# set-shell troca o shell de login entre /bin/bash (acesso liberado) e
# /sbin/nologin (padrão, sem shell) — autenticação continua sempre só
# por chave pública (PasswordAuthentication já é "no" globalmente no
# sshd_config, não muda por conta).
#
# sync-keys lê pelo STDIN o conteúdo completo já validado (uma chave
# pública por linha, a Action em PHP já validou o formato) e regrava
# ~/.ssh/authorized_keys por inteiro — mesmo padrão idempotente das
# outras sincronizações (cron, backup).
set -euo pipefail

USERNAME="${1:-}"
OPERATION="${2:-}"

if [[ ! "$USERNAME" =~ ^[a-z][a-z0-9]{2,31}$ ]]; then
    echo "Username inválido: $USERNAME" >&2
    exit 1
fi

HOME_DIR="/home/$USERNAME"

if [[ ! -d "$HOME_DIR" ]]; then
    echo "Home não existe: $HOME_DIR" >&2
    exit 1
fi

case "$OPERATION" in
    set-shell)
        STATE="${3:-}"
        # usermod (como root) ignora a checagem de /etc/shells que o
        # chsh normalmente exige — evita falhar caso /sbin/nologin não
        # esteja listado lá em alguma distro.
        case "$STATE" in
            enabled) usermod -s /bin/bash "$USERNAME" ;;
            disabled) usermod -s /sbin/nologin "$USERNAME" ;;
            *) echo "Estado inválido: $STATE" >&2; exit 1 ;;
        esac
        ;;
    sync-keys)
        SSH_DIR="$HOME_DIR/.ssh"
        mkdir -p "$SSH_DIR"

        TMP_FILE=$(mktemp)
        trap 'rm -f "$TMP_FILE"' EXIT
        cat > "$TMP_FILE"

        mv "$TMP_FILE" "$SSH_DIR/authorized_keys"
        chown -R "$USERNAME:$USERNAME" "$SSH_DIR"
        chmod 700 "$SSH_DIR"
        chmod 600 "$SSH_DIR/authorized_keys"
        ;;
    *)
        echo "Operação desconhecida: $OPERATION" >&2
        exit 1
        ;;
esac

echo "OK"

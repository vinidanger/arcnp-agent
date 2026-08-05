#!/bin/bash
# Uso: set-disk-quota.sh <username> <quota_mb> <mount>
#
# Cota de disco REAL via user quota tradicional (setquota -u) — não
# XFS project quota. Cada conta já tem 1 UID Linux dedicado, então
# quota por usuário mapeia 1:1 sem precisar de um ID de projeto à
# parte, e funciona tanto em ext4 (usrquota) quanto XFS (uquota).
# quota_mb=0 significa sem limite (remove a cota).
#
# Pré-requisito de deploy: o filesystem de $MOUNT precisa estar
# montado com quota de usuário habilitada e (em ext4) já ter passado
# por "quotacheck -cum" + "quotaon" — ver seção de cota de disco do
# deploy/README.md. Sem isso "setquota" falha com uma mensagem clara
# ("quotactl: Function not implemented" ou similar), que já sobe pro
# Painel via RuntimeException — sem tratamento especial necessário
# aqui além de deixar o erro real do comando passar.
set -euo pipefail

USERNAME="${1:-}"
QUOTA_MB="${2:-0}"
MOUNT="${3:-}"

if [[ ! "$USERNAME" =~ ^[a-z][a-z0-9]{2,31}$ ]]; then
    echo "Username inválido: $USERNAME" >&2
    exit 1
fi

if [[ ! "$QUOTA_MB" =~ ^[0-9]{1,9}$ ]]; then
    echo "quota_mb inválido: $QUOTA_MB" >&2
    exit 1
fi

if [[ ! "$MOUNT" =~ ^/[a-zA-Z0-9_/-]*$ ]]; then
    echo "mount inválido: $MOUNT" >&2
    exit 1
fi

if ! id -u "$USERNAME" >/dev/null 2>&1; then
    echo "Usuário não existe: $USERNAME" >&2
    exit 1
fi

# Blocos em KiB (unidade padrão do setquota, sem sufixo de unidade).
# Sem limite de inodes — só espaço, mesma semântica de disk_quota_mb.
QUOTA_KIB=$((QUOTA_MB * 1024))

setquota -u "$USERNAME" 0 "$QUOTA_KIB" 0 0 "$MOUNT"

echo "OK"

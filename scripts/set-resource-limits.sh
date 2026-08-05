#!/bin/bash
# Uso: set-resource-limits.sh <username> <cpu_percent|infinity> <memory_mb|infinity> <tasks_max|infinity> <io_weight>
#
# Aplica CPU/RAM/processos/I-O no cgroup nativo do systemd pro usuário
# (user-{uid}.slice) — nenhum slice customizado é criado, reaproveita
# o que o próprio systemd já gerencia por UID. "loginctl enable-linger"
# garante que esse slice continua existindo mesmo sem sessão de login
# ativa (contas de hospedagem não fazem login interativo real).
#
# Autorizado via sudoers com argumento livre — validação de cada campo
# é feita AQUI, nunca só no lado PHP que chama.
set -euo pipefail

USERNAME="${1:-}"
CPU="${2:-infinity}"
MEMORY="${3:-infinity}"
TASKS="${4:-infinity}"
IOWEIGHT="${5:-100}"

if [[ ! "$USERNAME" =~ ^[a-z][a-z0-9]{2,31}$ ]]; then
    echo "Username inválido: $USERNAME" >&2
    exit 1
fi

if [[ "$CPU" != "infinity" && ! "$CPU" =~ ^[1-9][0-9]{0,4}$ ]]; then
    echo "cpu_percent inválido: $CPU" >&2
    exit 1
fi

if [[ "$MEMORY" != "infinity" && ! "$MEMORY" =~ ^[1-9][0-9]{0,6}$ ]]; then
    echo "memory_mb inválido: $MEMORY" >&2
    exit 1
fi

if [[ "$TASKS" != "infinity" && ! "$TASKS" =~ ^[1-9][0-9]{0,5}$ ]]; then
    echo "tasks_max inválido: $TASKS" >&2
    exit 1
fi

if [[ ! "$IOWEIGHT" =~ ^([1-9][0-9]{0,3}|10000)$ ]]; then
    echo "io_weight inválido: $IOWEIGHT" >&2
    exit 1
fi

if ! id -u "$USERNAME" >/dev/null 2>&1; then
    echo "Usuário não existe: $USERNAME" >&2
    exit 1
fi

UID_NUM=$(id -u "$USERNAME")

CPU_VALUE="infinity"
if [[ "$CPU" != "infinity" ]]; then
    CPU_VALUE="${CPU}%"
fi

MEMORY_VALUE="infinity"
if [[ "$MEMORY" != "infinity" ]]; then
    MEMORY_VALUE="${MEMORY}M"
fi

loginctl enable-linger "$USERNAME"

systemctl set-property "user-${UID_NUM}.slice" \
    CPUQuota="$CPU_VALUE" \
    MemoryMax="$MEMORY_VALUE" \
    TasksMax="$TASKS" \
    IOWeight="$IOWEIGHT"

echo "OK"

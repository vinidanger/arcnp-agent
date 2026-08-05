#!/bin/bash
# Uso: reload-php-fpm-for-binary.sh <binary_path>
#
# Consequência direta de cada conta ter seu próprio processo mestre de
# PHP-FPM (ver manage-php-fpm-service.sh): alternar uma extensão a
# nível de SERVIDOR (TogglePhpExtensionAction) não tem mais um único
# service compartilhado pra recarregar — precisa reiniciar TODOS os
# units de conta que usam aquele binário/versão. Best-effort por unit:
# uma conta com problema não trava o restart das demais.
set -euo pipefail

BINARY="${1:-}"

if [[ ! "$BINARY" =~ ^/usr/bin/[a-zA-Z0-9_-]+$ ]]; then
    echo "Binário inválido: $BINARY" >&2
    exit 1
fi

shopt -s nullglob
for unit_file in /etc/systemd/system/arcnp-php-*.service; do
    if grep -qF "ExecStart=${BINARY} " "$unit_file"; then
        unit=$(basename "$unit_file")
        systemctl restart "$unit" || echo "Falha ao reiniciar $unit" >&2
    fi
done

echo "OK"

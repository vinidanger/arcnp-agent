#!/bin/bash
# Uso: renew-ssl-certificates.sh
#
# Sem argumento de propósito — certbot já sabe quais domínios foram
# emitidos por ele (lê /etc/letsencrypt/renewal/*.conf) e só renova o
# que estiver a menos de 30 dias de expirar. Um comando cobre TODOS os
# certificados desse servidor de uma vez, não é por conta.
set -euo pipefail

certbot renew --non-interactive --deploy-hook "systemctl reload nginx"

echo "OK"

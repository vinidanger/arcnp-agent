#!/bin/bash
# Uso: renew-ssl-certificates.sh
#
# Sem argumento de propósito — certbot já sabe quais domínios foram
# emitidos por ele (lê /etc/letsencrypt/renewal/*.conf) e só renova o
# que estiver a menos de 30 dias de expirar. Um comando cobre TODOS os
# certificados desse servidor de uma vez, não é por conta.
set -euo pipefail

certbot renew --non-interactive --deploy-hook "systemctl reload nginx"

# Reporta a expiração de TODO certificado que existe no servidor (não só
# o que acabou de renovar agora) — barato (só leitura local), e evita o
# Painel ter que adivinhar quais domínios foram tocados nessa passada.
for dir in /etc/letsencrypt/live/*/; do
    domain=$(basename "$dir")
    if [[ -f "$dir/cert.pem" ]]; then
        expires=$(openssl x509 -enddate -noout -in "$dir/cert.pem" | sed 's/^notAfter=//')
        echo "CERT:$domain:$expires"
    fi
done

echo "OK"

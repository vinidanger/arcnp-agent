#!/bin/bash
# Uso: issue-ssl-certificate.sh <domain> <webroot> <email>
set -euo pipefail

DOMAIN="${1:-}"
WEBROOT="${2:-}"
EMAIL="${3:-}"

if [[ ! "$DOMAIN" =~ ^[a-z0-9]([a-z0-9.-]{0,251}[a-z0-9])?$ ]]; then
    echo "Domínio inválido: $DOMAIN" >&2
    exit 1
fi

if [[ ! -d "$WEBROOT" ]]; then
    echo "Webroot não existe: $WEBROOT" >&2
    exit 1
fi

if [[ -z "$EMAIL" ]]; then
    echo "E-mail obrigatório" >&2
    exit 1
fi

certbot certonly --webroot -w "$WEBROOT" -d "$DOMAIN" \
    --non-interactive --agree-tos -m "$EMAIL" --no-eff-email

EXPIRES=$(openssl x509 -enddate -noout -in "/etc/letsencrypt/live/$DOMAIN/cert.pem" | sed 's/^notAfter=//')
echo "EXPIRES_AT=$EXPIRES"

echo "OK"

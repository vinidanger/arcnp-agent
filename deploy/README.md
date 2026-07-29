# Deploy do Arcnp Agent (AlmaLinux / Rocky Linux)

Este documento cobre a instalação do Agent em um servidor de hospedagem
gerenciado. Assume Nginx + PHP-FPM já instalados no servidor (para as
contas de hospedagem) e acesso root para a instalação inicial.

## 1. Usuário dedicado

O Agent nunca roda como root. Comandos privilegiados passam por sudo,
autorizado apenas para binários/scripts fixos (ver `sudoers/arcnp-agent`).

```
useradd --system --home-dir /opt/arcnp-agent --shell /sbin/nologin arcnpagent
```

## 2. Código

```
git clone <repo> /opt/arcnp-agent
cd /opt/arcnp-agent
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
```

Preencher no `.env`:
- `AGENT_SERVER_ID` — o mesmo ID gerado pelo Painel no pareamento deste servidor
- `AGENT_SHARED_SECRET` — segredo gerado pelo Painel no pareamento (nunca reaproveitar entre servidores)
- `AGENT_PANEL_BASE_URL` — URL base do Painel (usada para montar as chamadas de callback e heartbeat)

```
php artisan migrate --force
chmod +x scripts/*.sh
chown -R arcnpagent:arcnpagent /opt/arcnp-agent
```

## 3. PHP-FPM

Copiar `php-fpm/arcnp-agent.conf` para `/etc/php-fpm.d/arcnp-agent.conf` e
recarregar:

```
cp deploy/php-fpm/arcnp-agent.conf /etc/php-fpm.d/arcnp-agent.conf
systemctl restart php-fpm
```

## 4. Certificado TLS do Agent

Self-signed é suficiente (a assinatura HMAC já garante autenticidade;
o TLS aqui é só confidencialidade em trânsito):

```
mkdir -p /etc/pki/arcnp-agent
openssl req -x509 -nodes -days 3650 -newkey rsa:2048 \
  -keyout /etc/pki/arcnp-agent/agent.key \
  -out /etc/pki/arcnp-agent/agent.crt \
  -subj "/CN=$(hostname -f)"
```

## 5. Nginx

```
cp deploy/nginx/arcnp-agent.conf /etc/nginx/conf.d/arcnp-agent.conf
nginx -t && systemctl reload nginx
```

## 6. Diretórios de config das contas de hospedagem

As Actions de vhost e pool PHP-FPM escrevem os arquivos de config
diretamente (sem sudo) — só o `nginx -t`/reload e a criação do
usuário Linux exigem privilégio. Por isso o grupo do Agent precisa de
escrita nesses dois diretórios:

```
chgrp arcnpagent /etc/nginx/conf.d /etc/php-fpm.d
chmod 2775 /etc/nginx/conf.d /etc/php-fpm.d
```

(`2775` = setgid, para que todo arquivo novo criado ali herde o grupo
`arcnpagent`, incluindo os que o próprio root cria manualmente.)

## 7. Firewall — só o Painel pode falar com o Agent

```
firewall-cmd --permanent --add-rich-rule='rule family="ipv4" source address="<IP_DO_PAINEL>/32" port port="8443" protocol="tcp" accept'
firewall-cmd --permanent --add-rich-rule='rule family="ipv4" port port="8443" protocol="tcp" reject'
firewall-cmd --reload
```

## 8. Sudoers

```
install -m 0440 -o root -g root deploy/sudoers/arcnp-agent /etc/sudoers.d/arcnp-agent
visudo -c
```

Autoriza exatamente 5 comandos: criar/remover usuário de hospedagem
(via os scripts em `scripts/`), testar config do nginx e recarregar
nginx/php-fpm. Nada além disso.

## 9. Fila assíncrona (systemd)

```
cp deploy/systemd/arcnp-agent-queue.service /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now arcnp-agent-queue
```

## 10. Heartbeat (systemd timer)

```
cp deploy/systemd/arcnp-agent-heartbeat.service deploy/systemd/arcnp-agent-heartbeat.timer /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now arcnp-agent-heartbeat.timer
```

Envia um snapshot (load average, % disco, % memória) a cada 60s. O
Painel marca o servidor `offline` automaticamente se não receber
heartbeat por tempo demais (ver `servers:mark-stale-offline` no Painel).

## Ainda não incluído

- **Banco de dados, SSL, suspender/reativar conta, domínios adicionais**
  — ficam para a Fase 6 (não faziam parte do escopo mínimo de "criar
  conta de hospedagem": usuário Linux + vhost + pool PHP-FPM).

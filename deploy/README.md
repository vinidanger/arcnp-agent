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
- `AGENT_MYSQL_ADMIN_USER` / `AGENT_MYSQL_ADMIN_PASSWORD` — credenciais do usuário MySQL dedicado do Agent (ver seção 11)

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

## 6. PHP-FPM separado para contas de hospedagem

Toda vez que uma conta é criada/removida, o Agent recarrega o PHP-FPM
pra ativar o pool novo — e um reload derruba momentaneamente **todos**
os pools daquele serviço. Se fosse o mesmo `php-fpm.service` do
Painel/Agent, cada conta nova causaria uma interrupção breve no
próprio Painel (rodando no mesmo host). Por isso as contas de
hospedagem ficam num serviço `php-fpm-hosting` totalmente separado:

```
mkdir -p /etc/php-fpm-hosting.d /var/log/php-fpm
cp deploy/php-fpm/php-fpm-hosting.conf /etc/php-fpm-hosting.conf
cp deploy/systemd/php-fpm-hosting.service /etc/systemd/system/

systemctl daemon-reload
systemctl enable --now php-fpm-hosting
```

E os diretórios de config que as Actions escrevem diretamente (sem
sudo — só o `nginx -t`/reload e a criação do usuário Linux exigem
privilégio):

```
chgrp arcnpagent /etc/nginx/conf.d /etc/php-fpm-hosting.d
chmod 2775 /etc/nginx/conf.d /etc/php-fpm-hosting.d
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

Autoriza exatamente os comandos fixos que o Agent precisa (todos em
`scripts/*.sh`, testar/recarregar nginx e php-fpm-hosting) — repare que
não autoriza recarregar o `php-fpm.service` do Painel/Agent, só o
`php-fpm-hosting`.

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

## 11. Usuário MySQL administrativo do Agent

O Agent precisa criar banco/usuário MySQL por conta de hospedagem, mas
**nunca** recebe a senha real do `root` do MariaDB — só um usuário
dedicado:

```
mysql -u root -p
```

```sql
CREATE USER 'arcnpagent'@'localhost' IDENTIFIED BY 'SENHA_FORTE_AQUI';
GRANT ALL PRIVILEGES ON *.* TO 'arcnpagent'@'localhost' WITH GRANT OPTION;
FLUSH PRIVILEGES;
```

`WITH GRANT OPTION` só deixa delegar privilégios que a própria conta
possui — por isso não dá pra restringir a `CREATE/DROP` apenas: pra
poder fazer `GRANT ALL PRIVILEGES` no banco de cada conta de
hospedagem (o passo final de `database.create_mysql`), o `arcnpagent`
precisa ter esses privilégios de dados também. Na prática isso dá a
essa conta acesso equivalente a root do MySQL — mesma coisa que a
maioria dos painéis de hospedagem reais faz.

Preenche `AGENT_MYSQL_ADMIN_USER=arcnpagent` e
`AGENT_MYSQL_ADMIN_PASSWORD=SENHA_FORTE_AQUI` no `.env` do Agent.

## 12. SSL (Let's Encrypt)

```
dnf install -y certbot
```

Preenche `AGENT_SSL_ADMIN_EMAIL` no `.env` do Agent (e-mail da conta
Let's Encrypt, usado só para avisos de renovação/expiração).

A emissão usa o método `--webroot` contra o vhost HTTP simples que já
existe (`CreateVirtualHostAction`) — o domínio precisa estar resolvendo
de verdade para este servidor antes de emitir. Renovação automática já
vem com o pacote `certbot` (timer `certbot-renew.timer` próprio do
pacote), cobre os certificados emitidos pelo Agent também, sem
configuração extra.

## 13. Domínios adicionais / subdomínios

Sem passo extra de instalação — reaproveitam o mesmo usuário Linux,
pool PHP-FPM e ACL da conta principal (só ganham um subdiretório
dedicado dentro de `public_html` e seu próprio vhost).

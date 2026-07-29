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

**Importante em todo deploy futuro:** esse é um processo `queue:work` de
longa duração — ele carrega o código PHP uma vez na inicialização e não
recarrega sozinho depois de um `git pull`. As Actions síncronas (pool,
vhost, etc.) pegam código novo a cada request porque passam pelo
PHP-FPM normal, mas as assíncronas (hoje só `ssl.issue_certificate`)
continuam rodando com o código antigo até reiniciar o serviço:

```
systemctl restart arcnp-agent-queue
```

Sempre rodar isso depois de qualquer `git pull` no Agent.

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

## 14. Múltiplas versões de PHP (8.1 / 8.2 / 8.4)

O 8.3 já é a versão padrão do sistema (`php-fpm-hosting.service`,
seção 6). As demais vêm do Remi como pacotes paralelos — cada uma
isolada no próprio `systemd service`, exatamente pelo mesmo motivo do
8.3 estar separado do Painel/Agent: reload de pool de uma versão nunca
pode afetar contas de outra.

```
dnf install -y dnf-plugins-core

for v in 81 82 84; do
  dnf config-manager --set-enabled remi-php$v
  dnf install -y php$v php$v-php-fpm php$v-php-common php$v-php-mysqlnd \
    php$v-php-xml php$v-php-mbstring php$v-php-curl php$v-php-zip \
    php$v-php-bcmath php$v-php-gd php$v-php-intl php$v-php-opcache
done
```

Cada versão vem com um pool padrão ("www") que não usamos — mesmo
problema do "serviço recusa subir sem nenhum pool" que resolvemos na
seção 6, então cada uma ganha um placeholder também:

```
mkdir -p /var/log/php-fpm

for v in 81 82 84; do
  rm -f /etc/opt/remi/php$v/php-fpm.d/www.conf

  cat > /etc/opt/remi/php$v/php-fpm.d/_placeholder.conf << EOF
[placeholder]
user = arcnpagent
group = arcnpagent
listen = /run/php$v-fpm/_placeholder.sock
pm = ondemand
pm.max_children = 1
EOF

  mkdir -p /run/php$v-fpm
  chown arcnpagent:arcnpagent /run/php$v-fpm

  chgrp arcnpagent /etc/opt/remi/php$v/php-fpm.d
  chmod 2775 /etc/opt/remi/php$v/php-fpm.d

  systemctl enable --now php$v-php-fpm
  systemctl status php$v-php-fpm --no-pager
done
```

Se o `/run/php$v-fpm` não sobreviver a um reboot (diretórios em `/run`
são tmpfs, recriados vazios), considere adicionar
`RuntimeDirectory=php$v-fpm` ao unit file de cada serviço via
`systemctl edit php$v-php-fpm` — mesma lógica do
`deploy/systemd/php-fpm-hosting.service`.

## 15. phpMyAdmin (acesso via SSO do Painel)

Instância única por servidor (não por conta), numa pool/vhost isolados
— acesso entra sempre via link de uso único gerado pelo Painel, nunca
por senha digitada direto no phpMyAdmin. Ver `app/Support/DatabaseSsoToken`
no Painel pra como o token é gerado.

Usuário e diretórios dedicados:

```
useradd --system --home-dir /opt/phpmyadmin --shell /sbin/nologin pma

mkdir -p /opt/phpmyadmin /var/lib/pma-sso/sessions /var/lib/pma-sso/nonces /var/log/php-fpm
chown -R pma:pma /opt/phpmyadmin /var/lib/pma-sso
```

Baixar o phpMyAdmin (troque a versão se quiser outra) direto do site
oficial — evita depender de pacote de repositório de terceiros:

```
cd /tmp
curl -LO https://www.phpmyadmin.net/downloads/phpMyAdmin-latest-all-languages.tar.gz
tar -xzf phpMyAdmin-latest-all-languages.tar.gz
rsync -a --delete phpMyAdmin-*-all-languages/ /opt/phpmyadmin/
rm -rf phpMyAdmin-*-all-languages phpMyAdmin-latest-all-languages.tar.gz
chown -R pma:pma /opt/phpmyadmin
```

Script-ponte de SSO e config, a partir dos templates deste repo:

```
cp deploy/phpmyadmin/sso-login.php /opt/phpmyadmin/sso-login.php
cp deploy/phpmyadmin/config.inc.php.example /opt/phpmyadmin/config.inc.php
cp deploy/phpmyadmin/pma-secret.php.example /opt/phpmyadmin/pma-secret.php
chown pma:pma /opt/phpmyadmin/sso-login.php /opt/phpmyadmin/config.inc.php /opt/phpmyadmin/pma-secret.php
chmod 0400 /opt/phpmyadmin/pma-secret.php
```

Editar os dois arquivos copiados:
- `config.inc.php` — gerar `blowfish_secret` com `openssl rand -base64 32 | head -c 32`
- `pma-secret.php` — colar o MESMO valor de `AGENT_SHARED_SECRET` do `.env` deste Agent (`grep AGENT_SHARED_SECRET /opt/arcnp-agent/.env`)

Pool PHP-FPM isolada e serviço:

```
mkdir -p /etc/php-fpm-pma.d
cp deploy/php-fpm/php-fpm-pma.conf /etc/php-fpm-pma.conf
cp deploy/php-fpm/pma-pool.conf /etc/php-fpm-pma.d/pma.conf
cp deploy/systemd/php-fpm-pma.service /etc/systemd/system/

systemctl daemon-reload
systemctl enable --now php-fpm-pma
```

Nginx (porta própria 8444, nunca 80/443 — essas ficam só pros vhosts
de contas de hospedagem):

```
cp deploy/nginx/phpmyadmin.conf /etc/nginx/conf.d/phpmyadmin.conf
nginx -t && systemctl reload nginx
```

SELinux (se estiver enforcing) e firewall — porta pública dessa vez
(o navegador do admin/cliente acessa direto, diferente da 8443 que é
só Painel → Agent):

```
setsebool -P httpd_can_network_connect_db 1
firewall-cmd --permanent --add-port=8444/tcp
firewall-cmd --reload
```

Limpeza dos nonces de uso único (evita acúmulo indefinido de arquivos
— cada um é só alguns bytes, mas sem limpeza cresce pra sempre):

```
cat > /etc/cron.d/pma-sso-nonces << 'EOF'
15 * * * * root find /var/lib/pma-sso/nonces -mmin +60 -delete
EOF
```

## 16. Backup

Sem passo de instalação novo além de conferir que o cliente do MySQL
está presente (o dump usa o binário `mysqldump`, não uma extensão PHP):

```
which mysqldump || dnf install -y mariadb
```

Reinstalar o sudoers depois do deploy (ganhou a linha do
`create-backup.sh`, ver seção 8) e conferir permissão de execução:

```
chmod +x scripts/create-backup.sh
install -m 0440 -o root -g root deploy/sudoers/arcnp-agent /etc/sudoers.d/arcnp-agent
visudo -c
```

O dump roda sem privilégio (usuário `arcnpagent` já tem acesso total
ao MySQL via a conexão `mysql_admin`, mesma credencial da seção 11) —
só o `tar` do `public_html` e a movimentação dos dumps pra dentro do
home do cliente exigem root, por isso passam pelo script sudo. Nada
fica em `/opt/arcnp-agent/storage` depois de pronto: o diretório
temporário de dump é apagado ao final de cada execução, sucesso ou
falha.

## 17. Gerenciador de arquivos

Listar/ler é leitura direta (ACL do `arcnpagent` em `public_html`,
concedida por `create-hosting-user.sh` — contas criadas ANTES dessa
mudança precisam da ACL retroativa abaixo). Criar/salvar/apagar/
renomear passam pelo script sudo `manage-file.sh`, que ajusta a posse
de volta pro dono da conta depois.

```
chmod +x scripts/manage-file.sh
install -m 0440 -o root -g root deploy/sudoers/arcnp-agent /etc/sudoers.d/arcnp-agent
visudo -c
```

Pra contas já existentes (criadas antes dessa fase), rodar uma vez pra
cada uma — ou em lote, iterando `/home/*`:

```
for HOME_DIR in /home/*; do
  USERNAME=$(basename "$HOME_DIR")
  [[ -d "$HOME_DIR/public_html" ]] || continue
  setfacl -m u:arcnpagent:x "$HOME_DIR"
  setfacl -R -m u:arcnpagent:rx "$HOME_DIR/public_html"
  setfacl -d -m u:arcnpagent:rx "$HOME_DIR/public_html"
done
```

O gerenciador fica restrito a `public_html` — nunca o home inteiro
(não expõe `backups/`, `logs/` etc. a navegação/exclusão por engano).

## 18. Uso de disco (cota de plano)

`du` no home inteiro (`public_html` + `backups/` + `logs/`) exige root
— a ACL do Agent cobre só `public_html` e os arquivos de backup
específicos, não o home todo. Só sudoers, sem instalação nova:

```
chmod +x scripts/disk-usage.sh
install -m 0440 -o root -g root deploy/sudoers/arcnp-agent /etc/sudoers.d/arcnp-agent
visudo -c
```

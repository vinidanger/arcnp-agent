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

**Download em lote (zip) e remoção de backup** — reaproveita o `zip`
já instalado na seção 25 (compactar/extrair no gerenciador de
arquivos), sem pacote novo.
Ganhou uma linha nova no sudoers (`delete-backup.sh`, apagar exige
escrita no diretório `backups/`, que o `arcnpagent` não tem por padrão
— só leitura via ACL):

```
chmod +x scripts/delete-backup.sh
install -m 0440 -o root -g root deploy/sudoers/arcnp-agent /etc/sudoers.d/arcnp-agent
visudo -c
```

O zip de "Completo"/"Bancos de dados" é montado em
`storage/app/backup-zip-tmp` (apagado logo depois do download) — não
precisa de privilégio, é a mesma leitura via ACL que o download de
arquivo único já usa.

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

## 19. Cron jobs do cliente

Cada conta ganha um arquivo `/etc/cron.d/arcnp-{username}` (formato
cron.d, com o campo de usuário — roda como o dono da conta, nunca como
root). O Painel é sempre a fonte da verdade: toda mudança reenvia a
lista completa, o Agent reescreve o arquivo inteiro.

```
chmod +x scripts/sync-cron.sh
install -m 0440 -o root -g root deploy/sudoers/arcnp-agent /etc/sudoers.d/arcnp-agent
visudo -c
```

Sem passo de instalação — `cron`/`crond` já observa `/etc/cron.d/`
automaticamente, não precisa reiniciar nada. Ao excluir uma conta,
`delete-hosting-user.sh` já remove o arquivo junto.

## 20. Acesso SSH por conta

Shell completo (`/bin/bash`), nunca chroot — libera/revoga trocando o
shell de login entre `/bin/bash` e `/sbin/nologin` (padrão). Login por
senha E por chave convivem (decisão do produto): chaves públicas ficam
em `~/.ssh/authorized_keys` (reescrito por inteiro a cada
sincronização, mesmo padrão do cron); senha é uma senha Unix normal da
conta, trocada via `chpasswd`.

```
chmod +x scripts/manage-ssh.sh
install -m 0440 -o root -g root deploy/sudoers/arcnp-agent /etc/sudoers.d/arcnp-agent
visudo -c
```

**Pré-requisito obrigatório, não pular**: autenticação por senha
precisa estar LIGADA no SSH do servidor pra login por senha funcionar
(o padrão do OpenSSH já costuma vir assim, mas confirma):

```
grep -i '^PasswordAuthentication' /etc/ssh/sshd_config
```

Se mostrar `PasswordAuthentication no`:

```
sed -i 's/^#\?PasswordAuthentication.*/PasswordAuthentication yes/' /etc/ssh/sshd_config
systemctl restart sshd
```

(Confirma antes que você já tem acesso por chave configurado pro
próprio usuário `root`/admin — reiniciar o sshd com config quebrada te
tranca fora do servidor.)

Login por senha é inerentemente mais fraco que só chave (força bruta é
possível) — considerar `fail2ban` monitorando o `sshd` pra mitigar,
mesma recomendação que qualquer painel com essa opção (cPanel,
DirectAdmin) já dá.

## 21. Zona DNS (BIND9)

Servidor autoritativo próprio — cada domínio com zona vira um arquivo
em `/etc/named/zones/{domínio}.zone`, listado em
`/etc/named/zones.conf` (o Painel sempre reenvia a lista COMPLETA de
zonas ativas nesse servidor a cada criação/exclusão, o Agent reescreve
esse arquivo inteiro — mesmo padrão idempotente do cron/SSH).

```
dnf install -y bind bind-utils

mkdir -p /etc/named/zones
touch /etc/named/zones.conf
chown -R named:named /etc/named/zones /etc/named/zones.conf
```

Adicionar a inclusão no `/etc/named.conf` (fora do bloco `options {}`,
normalmente logo depois dele):

```
include "/etc/named/zones.conf";
```

```
named-checkconf
systemctl enable --now named
```

Firewall (DNS usa TCP e UDP na porta 53 — diferente do resto do Agent,
essa porta precisa ficar aberta pra qualquer resolver do mundo
consultar, não só o IP do Painel):

```
firewall-cmd --permanent --add-port=53/tcp
firewall-cmd --permanent --add-port=53/udp
firewall-cmd --reload
```

Sudoers (script novo):

```
chmod +x scripts/manage-dns-zone.sh
install -m 0440 -o root -g root deploy/sudoers/arcnp-agent /etc/sudoers.d/arcnp-agent
visudo -c
```

**Pré-requisito fora do nosso sistema**: pra esse BIND ser autoritativo
de verdade pra um domínio, os registros NS dele no REGISTRADOR
precisam apontar pra um hostname (ex: `ns1.seudominio.com`) que
resolva pro IP desse servidor — isso é configuração de glue
record/registrador, não tem como automatizar. Sem isso, a zona existe
aqui mas nenhum resolver do mundo pergunta pra ela.

Essa é a integração mais sensível a infraestrutura real que já fizemos
— não seria surpresa se `named-checkconf`/`named-checkzone` pegarem
algo específico da distro na primeira zona de teste. Testar criando
uma zona de teste antes de confiar em produção.

## 22. E-mail (Postfix + Dovecot)

Contas de e-mail são usuários VIRTUAIS do Postfix/Dovecot (não usuário
Linux de verdade) — a caixa fica em Maildir dentro do próprio home da
conta de hospedagem dona do domínio (`/home/{usuario}/mail/{domínio}/{caixa}/Maildir`),
então o espaço já entra na mesma conta que o `disk.usage` (seção 18)
mede — sem sistema de cota separado. O Painel manda o estado COMPLETO
do servidor (todo domínio com e-mail ativo + toda caixa + todo
encaminhamento) a cada mudança; o Agent reescreve os 6 arquivos de
mapeamento inteiros — mesmo padrão do cron/SSH/DNS.

```
dnf install -y postfix dovecot
```

Editar `/etc/postfix/main.cf` — adicionar (ou ajustar se já existirem)
essas diretivas ao final do arquivo:

```
myhostname = mail.SEUDOMINIO.com
smtpd_banner = $myhostname ESMTP

virtual_mailbox_domains = hash:/etc/postfix/virtual_domains
virtual_mailbox_maps = hash:/etc/postfix/virtual_mailbox_maps
virtual_alias_maps = hash:/etc/postfix/virtual_alias_maps
virtual_mailbox_base = /home
virtual_uid_maps = hash:/etc/postfix/virtual_uid_maps
virtual_gid_maps = hash:/etc/postfix/virtual_gid_maps
virtual_minimum_uid = 1000
virtual_transport = virtual

smtpd_sasl_type = dovecot
smtpd_sasl_path = private/auth
smtpd_sasl_auth_enable = yes
smtpd_recipient_restrictions =
    permit_sasl_authenticated,
    permit_mynetworks,
    reject_unauth_destination

smtpd_tls_cert_file = /etc/letsencrypt/live/mail.SEUDOMINIO.com/fullchain.pem
smtpd_tls_key_file = /etc/letsencrypt/live/mail.SEUDOMINIO.com/privkey.pem
smtpd_tls_security_level = may
smtp_tls_security_level = may
smtpd_tls_auth_only = yes
```

Troque `mail.SEUDOMINIO.com` pelo hostname de e-mail que você vai
cadastrar como `dns_ns1`-like no servidor (campo "Nameserver" já tem
um equivalente — esse aqui é um campo novo, "Hostname de e-mail", ver
seção do Painel). Certificado desse hostname: emitir manualmente uma
vez por servidor (não é por conta de hospedagem, então não passa pela
Action `ssl.issue_certificate`):

```
systemctl stop nginx
certbot certonly --standalone -d mail.SEUDOMINIO.com --agree-tos -m SEU@EMAIL.com --no-eff-email
systemctl start nginx
```

Habilitar submissão autenticada (porta 587) — no mesmo `main.cf`, ou
editando `/etc/postfix/master.cf`, descomentar/adicionar:

```
submission inet n       -       n       -       -       smtpd
  -o syslog_name=postfix/submission
  -o smtpd_tls_security_level=encrypt
  -o smtpd_sasl_auth_enable=yes
  -o smtpd_recipient_restrictions=permit_sasl_authenticated,reject
```

Dovecot — `/etc/dovecot/dovecot.conf` (ou um arquivo em
`/etc/dovecot/conf.d/`, como preferir organizar):

```
protocols = imap
mail_location = maildir:~/Maildir

ssl = required
ssl_cert = </etc/letsencrypt/live/mail.SEUDOMINIO.com/fullchain.pem
ssl_key = </etc/letsencrypt/live/mail.SEUDOMINIO.com/privkey.pem

passdb {
    driver = passwd-file
    args = /etc/dovecot/users
}
userdb {
    driver = passwd-file
    args = /etc/dovecot/users
}

service auth {
    unix_listener /var/spool/postfix/private/auth {
        mode = 0660
        user = postfix
        group = postfix
    }
}
```

`/etc/dovecot/users` é criado vazio pelo primeiro `mail.sync_state` —
só garanta que o diretório existe e o grupo `dovecot` também (o pacote
já cria):

```
touch /etc/dovecot/users
chown root:dovecot /etc/dovecot/users
chmod 640 /etc/dovecot/users

systemctl enable --now postfix dovecot
```

Firewall (portas públicas — clientes de e-mail do mundo inteiro
precisam alcançar, diferente da 8443 privada do Agent):

```
firewall-cmd --permanent --add-port=25/tcp
firewall-cmd --permanent --add-port=587/tcp
firewall-cmd --permanent --add-port=143/tcp
firewall-cmd --permanent --add-port=993/tcp
firewall-cmd --reload
```

Sudoers (2 scripts novos):

```
chmod +x scripts/manage-mail.sh scripts/manage-mail-dkim.sh
install -m 0440 -o root -g root deploy/sudoers/arcnp-agent /etc/sudoers.d/arcnp-agent
visudo -c
```

## 23. DKIM (assinatura de e-mail, via OpenDKIM)

```
dnf install -y opendkim
mkdir -p /etc/opendkim/keys
chown -R opendkim:opendkim /etc/opendkim/keys
touch /etc/opendkim/KeyTable /etc/opendkim/SigningTable
chown root:opendkim /etc/opendkim/KeyTable /etc/opendkim/SigningTable
```

Editar `/etc/opendkim.conf` (os principais, o resto o pacote já traz
com um padrão razoável):

```
Domain                  *
KeyTable                /etc/opendkim/KeyTable
SigningTable            refile:/etc/opendkim/SigningTable
ExternalIgnoreList      refile:/etc/opendkim/TrustedHosts
InternalHosts           refile:/etc/opendkim/TrustedHosts
Socket                  local:/run/opendkim/opendkim.sock
```

```
cat > /etc/opendkim/TrustedHosts << 'EOF'
127.0.0.1
localhost
::1
EOF

mkdir -p /run/opendkim
chown opendkim:opendkim /run/opendkim
```

Integrar no Postfix como milter — em `/etc/postfix/main.cf`:

```
milter_default_action = accept
milter_protocol = 6
smtpd_milters = local:/run/opendkim/opendkim.sock
non_smtpd_milters = local:/run/opendkim/opendkim.sock
```

```
systemctl enable --now opendkim
systemctl restart postfix
```

Nada de SPF/DMARC aqui — são só registros TXT (sem serviço nenhum
rodando), o Painel gera o conteúdo sugerido junto com o DKIM quando
o e-mail é ativado num domínio.

## 24. Webmail (Roundcube, acesso via SSO do Painel)

Instância única por servidor, mesmo padrão do phpMyAdmin (seção 15):
pool PHP-FPM isolada, vhost em porta própria, acesso normal via link
de uso único do Painel — só que aqui não existe `auth_type=signon`
nativo, então a ponte (`sso-login.php`) auto-submete o formulário de
login padrão do Roundcube com usuário/senha da caixa já preenchidos,
em vez de forjar sessão.

```
useradd --system --home-dir /opt/roundcube --shell /sbin/nologin roundcube
mkdir -p /opt/roundcube /var/lib/roundcube-sso/nonces /var/log/php-fpm
chown -R roundcube:roundcube /opt/roundcube /var/lib/roundcube-sso
```

Banco de dados próprio do Roundcube (preferências, catálogo de
endereços — as mensagens em si continuam só no Maildir via IMAP, isso
aqui é só metadado da aplicação):

```sql
CREATE DATABASE roundcube CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'roundcube'@'localhost' IDENTIFIED BY 'GERAR_SENHA_FORTE';
GRANT ALL PRIVILEGES ON roundcube.* TO 'roundcube'@'localhost';
FLUSH PRIVILEGES;
```

Baixar o Roundcube direto do GitHub — os arquivos de release têm a
versão no nome (ex: `roundcubemail-1.6.9-complete.tar.gz`), não existe
um link fixo sem versão, então descobre a URL certa via API antes de
baixar:

```
cd /tmp
URL=$(curl -s https://api.github.com/repos/roundcube/roundcubemail/releases/latest | grep '"browser_download_url":.*complete\.tar\.gz"' | cut -d '"' -f 4)
curl -LO "$URL"
tar -xzf roundcubemail-*-complete.tar.gz
rsync -a --delete roundcubemail-*/ /opt/roundcube/
rm -rf roundcubemail-*
chown -R roundcube:roundcube /opt/roundcube
```

Configurar (`/opt/roundcube/config/config.inc.php`, editar os
principais):

```php
$config['db_dsnw'] = 'mysql://roundcube:GERAR_SENHA_FORTE@localhost/roundcube';
$config['imap_host'] = 'ssl://MAILHOST:993';
$config['smtp_host'] = 'tls://MAILHOST:587';
$config['smtp_user'] = '%u';
$config['smtp_pass'] = '%p';
$config['des_key'] = 'GERAR_STRING_ALEATORIA_DE_24_CARACTERES';
```

Troque `MAILHOST` pelo hostname de e-mail real (ex: `smtp.arcn.cloud`) —
**não use `127.0.0.1`**: o certificado TLS do Dovecot é emitido pro
hostname, não pro IP, e a verificação de certificado do PHP rejeita a
conexão silenciosamente se os dois não baterem (aparece só como "login
falhou", sem pista nenhuma do motivo real).

Rodar o instalador de schema do banco (só na primeira vez):

```
mysql roundcube < /opt/roundcube/SQL/mysql.initial.sql
```

Script-ponte de SSO, a partir dos templates deste repo:

```
cp deploy/roundcube/sso-login.php /opt/roundcube/public_html/sso-login.php
cp deploy/roundcube/sso-secret.php.example /opt/roundcube/public_html/sso-secret.php
chown roundcube:roundcube /opt/roundcube/public_html/sso-login.php /opt/roundcube/public_html/sso-secret.php
chmod 0400 /opt/roundcube/public_html/sso-secret.php
```

Editar `sso-secret.php` — colar o MESMO valor de `AGENT_SHARED_SECRET`
do `.env` deste Agent (`grep AGENT_SHARED_SECRET /opt/arcnp-agent/.env`).

ACL pro nginx conseguir ler os arquivos estáticos (CSS/JS/imagem) —
o PHP-FPM roda como usuário `roundcube` e lê os próprios arquivos sem
problema, mas quem serve os arquivos estáticos é o nginx direto, com
outro usuário, mesma lógica do `create-hosting-user.sh`:

```
setfacl -R -m u:nginx:rx /opt/roundcube/public_html
setfacl -d -m u:nginx:rx /opt/roundcube/public_html
setfacl -m u:nginx:x /opt/roundcube
```

Diretório de sessão PHP dedicado — sem isso a sessão pode cair no
caminho padrão do sistema (fora do `open_basedir` da pool abaixo),
perdendo o estado entre requisições e disparando erro de CSRF
("Request check failed") a cada ação:

```
mkdir -p /var/lib/roundcube-sso/sessions
chown roundcube:roundcube /var/lib/roundcube-sso/sessions
```

Pool PHP-FPM isolada e serviço:

```
mkdir -p /etc/php-fpm-roundcube.d
cp deploy/php-fpm/php-fpm-roundcube.conf /etc/php-fpm-roundcube.conf
cp deploy/php-fpm/roundcube-pool.conf /etc/php-fpm-roundcube.d/roundcube.conf
echo 'php_admin_value[session.save_path] = /var/lib/roundcube-sso/sessions' >> /etc/php-fpm-roundcube.d/roundcube.conf
cp deploy/systemd/php-fpm-roundcube.service /etc/systemd/system/

systemctl daemon-reload
systemctl enable --now php-fpm-roundcube
```

Nginx (porta própria 8445 — a partir do Roundcube 1.6, CSS/JS/imagem
não ficam dentro de `public_html`, são servidos através do
`public_html/static.php`; o `deploy/nginx/roundcube.conf` já tem a
location certa pra isso):

```
cp deploy/nginx/roundcube.conf /etc/nginx/conf.d/roundcube.conf
nginx -t && systemctl reload nginx
```

Firewall (pública, igual à 8444 do phpMyAdmin):

```
firewall-cmd --permanent --add-port=8445/tcp
firewall-cmd --reload
```

Limpeza dos nonces de uso único (mesma lógica da seção 15):

```
cat > /etc/cron.d/roundcube-sso-nonces << 'EOF'
15 * * * * root find /var/lib/roundcube-sso/nonces -mmin +60 -delete
EOF
```

## 25. Compactar/extrair no gerenciador de arquivos

`manage-archive.sh` usa os binários `zip`/`unzip` direto — sem eles
instalados, toda tentativa de compactar/extrair falha:

```
dnf install -y zip unzip
```

```
chmod +x scripts/manage-archive.sh
install -m 0440 -o root -g root deploy/sudoers/arcnp-agent /etc/sudoers.d/arcnp-agent
visudo -c
```

## 26. Upload de arquivo (binário)

Endpoint novo (`POST /api/files/{username}/upload`) reaproveita a
mesma pool/serviço do Agent já em pé. O pool (`deploy/php-fpm/arcnp-agent.conf`)
e o vhost (`deploy/nginx/arcnp-agent.conf`) já vêm com `upload_max_filesize`/
`post_max_size`/`client_max_body_size` em 300M — teto folgado sobre o
limite configurável em Admin > Configurações no Painel (chave
"max_upload_mb", tabela `settings`, padrão 100 MB). Se o admin subir
esse valor pra além de 300 MB, precisa reinstalar este pool/vhost com
um número maior também.

O pool também sobe `memory_limit` pra 512M: o corpo inteiro do upload é
lido pra memória duas vezes no Agent (uma em `VerifySignedRequest`, pra
recalcular o HMAC sobre o corpo bruto, outra em `FileUploadController`)
— sem isso, o padrão de 128M estoura bem antes do teto de 300M acima
("Allowed memory size... exhausted").

E `max_execution_time` pra 200s: gravar um upload grande em disco (via
`manage-file.sh`, com sudo + ajuste de posse/ACL) pode passar do padrão
de 30s do PHP num disco mais lento ("Maximum execution time... exceeded",
capturado em `Symfony\Component\Process\Pipes\AbstractPipes`). Precisa
bater com dois outros tetos que também derrubam a mesma requisição se
ficarem menores: `Process::timeout(180)` em
`ProcessRunner::manageFile()` (código, já ajustado) e
`fastcgi_read_timeout`/`fastcgi_send_timeout` (200s) no vhost nginx —
o mais curto dos três mata a requisição primeiro, então todos precisam
ficar acima do tempo real de gravação.

Reinstalar os dois depois do deploy (mesmo comando da seção 3/5):

```
cp deploy/php-fpm/arcnp-agent.conf /etc/php-fpm.d/arcnp-agent.conf
cp deploy/nginx/arcnp-agent.conf /etc/nginx/conf.d/arcnp-agent.conf
systemctl restart php-fpm
nginx -t && systemctl reload nginx
```

**Isso resolve só o hop Painel → Agent.** O hop navegador → Painel
passa pelo nginx/PHP-FPM do próprio Painel, que não é gerenciado por
este repositório — conferir lá também (`client_max_body_size` do
vhost do Painel e `upload_max_filesize`/`post_max_size` do pool PHP
que ele usa), senão um arquivo grande é rejeitado ANTES de sequer
chegar no Agent, com o mesmo sintoma (erro genérico de conexão no
navegador).

## 27. Encaminhamentos de e-mail (forwarders)

Reaproveita a mesma sincronização de estado da seção 22 — o bundle
ganhou uma 6ª seção (`POSTFIX_VIRTUAL_ALIAS_MAPS`), instalada em
`/etc/postfix/virtual_alias_maps`. Servidor que já tem e-mail
configurado (instalação anterior à seção 22 acima incluir essa
diretiva) precisa da diretiva nova no `main.cf` — sem ela, o Postfix
ignora esse arquivo e o encaminhamento simplesmente não funciona,
sem erro nenhum:

```
touch /etc/postfix/virtual_alias_maps
postmap /etc/postfix/virtual_alias_maps
postconf -e 'virtual_alias_maps = hash:/etc/postfix/virtual_alias_maps'
postfix check
systemctl reload postfix
```

O `touch`+`postmap` vazio vem antes da diretiva no `main.cf` de
propósito — sem o `.db` existir, `postfix check`/`reload` falha
("table hash:... no such file") assim que a diretiva referenciar um
arquivo inexistente. Dali em diante, todo `mail.sync_state` (qualquer
criação/remoção de caixa, domínio ou encaminhamento) já reescreve e
refaz o `postmap` desse arquivo sozinho.

## 28. Autorresposta / aviso de férias (vacation) — MUDA O CAMINHO DE ENTREGA DE E-MAIL

**Atenção**: diferente de toda seção anterior, este item muda como o
Postfix ENTREGA e-mail pra todas as caixas do servidor, não só
adiciona um arquivo novo. Ler inteiro antes de aplicar, e aplicar em
horário de baixo tráfego — tem um passo de risco real (troca do
`virtual_transport`) com rollback documentado no fim.

**Por quê**: aviso de férias é implementado via Sieve (RFC 5230,
plugin `vacation`), e Sieve só roda dentro do agente de entrega final
do Dovecot (LDA ou LMTP) — nunca dentro do transporte nativo do
Postfix (`virtual_transport = virtual`, o que este servidor usa hoje,
seção 22). Não dá pra ter aviso de férias sem o Postfix entregar via
Dovecot. A troca é: Postfix para de escrever direto no Maildir e passa
a entregar via LMTP num socket do Dovecot, que aí sim escreve no
Maildir E roda o Sieve de cada caixa antes.

### 28.1. Instalar o Pigeonhole (plugin Sieve do Dovecot)

```
dnf install -y dovecot-pigeonhole
```

### 28.2. Dovecot — habilitar LMTP + Sieve

Editar o mesmo `/etc/dovecot/dovecot.conf` da seção 22:

```
protocols = imap lmtp

postmaster_address = postmaster@%d

service lmtp {
    unix_listener /var/spool/postfix/private/dovecot-lmtp {
        mode = 0600
        user = postfix
        group = postfix
    }
}

protocol lmtp {
    mail_plugins = $mail_plugins sieve
}

plugin {
    sieve = ~/.dovecot.sieve
}
```

`postmaster_address` é obrigatório pro Pigeonhole — é o remetente
usado em respostas automáticas/bounces gerados pelo próprio Dovecot.
Troque `%d` (domínio da caixa) se preferir um endereço fixo único.

### 28.3. Postfix — trocar o transporte de entrega

Esse é o passo que muda a entrega de TODAS as caixas do servidor,
de uma vez, no reload:

```
postconf -e 'virtual_transport = lmtp:unix:private/dovecot-lmtp'
postfix check
systemctl restart dovecot
systemctl reload postfix
```

`virtual_uid_maps`/`virtual_gid_maps` continuam declarados no
`main.cf` (seção 22) mas ficam sem uso — quem passa a decidir
dono/permissão do Maildir na entrega é o `userdb` do Dovecot
(`/etc/dovecot/users`, já com uid/gid corretos por linha), não mais o
transporte nativo do Postfix. Não precisa remover as diretivas
antigas, só não fazem mais efeito na entrega.

**Teste antes de considerar concluído**: mande um e-mail de teste pra
uma caixa existente e confirme que chegou (IMAP ou `mail.sync_state`
de novo pra qualquer conta) — isso valida que a entrega via LMTP está
funcionando antes de qualquer cliente notar problema.

### 28.4. Rollback (se a entrega via LMTP quebrar)

Reverte só a linha do transporte — o Sieve/LMTP fica configurado mas
inerte, sem afetar nada:

```
postconf -e 'virtual_transport = virtual'
postfix check
systemctl reload postfix
```

E-mail volta a ser entregue do jeito antigo (Postfix nativo,
seção 22), sem aviso de férias, mas sem risco de perda de mensagem —
o Postfix guarda em fila local se a entrega falhar, não descarta.

### 28.5. Sincronização (`manage-mail.sh`)

Reaproveita a mesma sincronização de estado das seções 22/27 — o
bundle ganhou uma 7ª seção (`SIEVE_VACATION`), cada linha no formato
`home:uid:gid:script_base64` (um script Sieve completo por caixa,
codificado em base64 porque o conteúdo tem quebras de linha). O
`manage-mail.sh` decodifica, escreve `{home}/.dovecot.sieve`, compila
com `sievec` (gera `{home}/.dovecot.svbin` — é o binário que o Dovecot
de fato executa, não o `.sieve` fonte) e ajusta a posse pro uid/gid da
caixa. Caixa que teve o aviso de férias desativado no Painel some
dessa seção no próximo sync, e o script apaga o `.sieve`/`.svbin`
antigo dela — sem essa limpeza o Dovecot continuaria respondendo
porque ele só olha se o arquivo existe no disco, não sabe de
"habilitado" no Painel.

Nenhum sudoers novo — `manage-mail.sh` já tem entrada `NOPASSWD` ampla
(`scripts/manage-mail.sh *`, ver seção 22), e o `sievec` roda dentro
do mesmo processo já root via sudo.

### 28.6. Firewall / rede

Nenhuma porta pública nova — o socket LMTP é Unix (`/var/spool/postfix/private/dovecot-lmtp`), só o Postfix local fala com ele, igual o `private/auth` do SASL da seção 22.

## 29. Proteção de pasta com senha (.htpasswd)

Reaproveita o mesmo mecanismo de escrita direta (sem sudo) da seção 6
— um diretório novo, group-writable pro usuário do Agent:

```
mkdir -p /etc/nginx/htpasswd
chgrp arcnpagent /etc/nginx/htpasswd
chmod 2775 /etc/nginx/htpasswd
```

Diferente do resto da seção de e-mail/DNS, aqui a Action
(`SyncFolderProtectionsAction`, ação `web.sync_folder_protections`)
**não regenera o vhost inteiro** — ela só reescreve o trecho entre os
marcadores `# ARCNP:PROTECTED-LOCATIONS:BEGIN`/`:END` dentro do
arquivo já existente em `/etc/nginx/conf.d/{domínio}.conf`. Se os
marcadores ainda não existirem (vhost criado antes dessa feature, ou
reescrito do zero por uma troca de versão de PHP/emissão de SSL — que
usam o stub, sem noção nenhuma de proteção de pasta), ela insere o
bloco automaticamente antes do último `}` do arquivo na primeira vez
que rodar — não precisa reprovisionar nada manualmente.

Cada regra vira um arquivo `/etc/nginx/htpasswd/{domínio}/{id}.htpasswd`
com uma linha `usuário:hash`. O hash chega pronto do Painel (bcrypt,
via `Hash::make()` — mesmo formato usado nas senhas de caixa de
e-mail) porque o `crypt()` do glibc/libxcrypt do AlmaLinux/Rocky 9
entende bcrypt nativamente; não precisa gerar com `htpasswd -B` na
mão. Regra removida no Painel some do próximo sync e o `.htpasswd`
correspondente é apagado.

**Importante**: como o Painel troca versão de PHP ou emite SSL
regenerando o vhost inteiro a partir do stub (seção 22-ish/14), essas
duas ações reenviam automaticamente as proteções de pasta daquele
domínio logo depois de reescrever o vhost — sem isso, o bloco de
proteção seria perdido silenciosamente na próxima troca de PHP/SSL. Se
notar uma pasta que "perdeu" a senha depois de mexer em PHP/SSL,
mande qualquer criação/remoção de proteção pelo Painel pra ela
resincronizar (ou seja, o auto-cura acima entra em ação de novo).

Nenhum sudoers novo — a Action só chama `nginx -t`/`reload`, que já
tem entrada própria (ver seção 8).

## 30. Redirecionamentos de site

Mesmo mecanismo de bloco-entre-marcadores da seção 29
(`VhostExtraBlock`, marcadores `# ARCNP:REDIRECTS:BEGIN`/`:END`) —
`SyncRedirectsAction` (ação `web.sync_redirects`) só troca o que entra
no bloco: `location {caminho} { return {301|302} {destino}; }` em vez
de `auth_basic`. Nenhum diretório novo, nenhum sudoers novo — não
precisa de nenhum passo de deploy além do `git pull` de sempre.

Mesmo cuidado da seção 29 se aplica aqui: troca de PHP e emissão de
SSL regeneram o vhost inteiro a partir do stub, então essas duas ações
também reenviam os redirecionamentos do domínio logo depois (mesmo
hook de `resyncIfNeeded`, agora chamado pros dois serviços).

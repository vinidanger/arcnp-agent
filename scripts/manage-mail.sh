#!/bin/bash
# Uso: manage-mail.sh sync-state   (conteúdo via STDIN, ver App\Support\MailStateBundle)
#
# O Painel sempre manda o estado COMPLETO (todos os domínios com e-mail
# ativo + todas as caixas do servidor inteiro) — este script reescreve
# os 5 arquivos inteiros a cada chamada, nunca faz diff incremental.
# Mesmo padrão do sync-cron.sh/manage-ssh.sh/manage-dns-zone.sh.
set -euo pipefail

OPERATION="${1:-}"

case "$OPERATION" in
    sync-state)
        TMP="$(mktemp -d)"
        trap 'rm -rf "$TMP"' EXIT

        awk -v dir="$TMP" '
            /^===.*===$/ {
                name = substr($0, 4, length($0) - 6)
                file = dir "/" name
                next
            }
            file { print > file }
        '

        for section in POSTFIX_VIRTUAL_DOMAINS POSTFIX_VIRTUAL_MAILBOX_MAPS POSTFIX_VIRTUAL_ALIAS_MAPS POSTFIX_VIRTUAL_UID_MAPS POSTFIX_VIRTUAL_GID_MAPS DOVECOT_USERS; do
            if [[ ! -f "$TMP/$section" ]]; then
                echo "Bundle inválido: faltando seção $section" >&2
                exit 1
            fi
        done

        install -m 0644 -o root -g root "$TMP/POSTFIX_VIRTUAL_DOMAINS" /etc/postfix/virtual_domains
        install -m 0644 -o root -g root "$TMP/POSTFIX_VIRTUAL_MAILBOX_MAPS" /etc/postfix/virtual_mailbox_maps
        install -m 0644 -o root -g root "$TMP/POSTFIX_VIRTUAL_ALIAS_MAPS" /etc/postfix/virtual_alias_maps
        install -m 0644 -o root -g root "$TMP/POSTFIX_VIRTUAL_UID_MAPS" /etc/postfix/virtual_uid_maps
        install -m 0644 -o root -g root "$TMP/POSTFIX_VIRTUAL_GID_MAPS" /etc/postfix/virtual_gid_maps
        install -m 0640 -o root -g dovecot "$TMP/DOVECOT_USERS" /etc/dovecot/users

        # Postfix/Dovecot não são confiáveis pra criar a árvore do Maildir
        # com a posse certa na primeira entrega — prepara aqui, igual ao
        # create-hosting-user.sh prepara public_html/logs antes do nginx
        # existir. Formato de cada linha (passwd-file do Dovecot):
        # email:hash:uid:gid::home::userdb_mail=maildir:CAMINHO
        while IFS=: read -r email hash uid gid gecos home shell extra; do
            [[ -z "$email" ]] && continue
            maildir="${extra#userdb_mail=maildir:}"
            mkdir -p "$maildir/new" "$maildir/cur" "$maildir/tmp"
            chown -R "$uid:$gid" "$home"
            chmod -R 0700 "$home"
        done < "$TMP/DOVECOT_USERS"

        postmap /etc/postfix/virtual_domains
        postmap /etc/postfix/virtual_mailbox_maps
        postmap /etc/postfix/virtual_alias_maps
        postmap /etc/postfix/virtual_uid_maps
        postmap /etc/postfix/virtual_gid_maps

        postfix check
        systemctl reload postfix
        systemctl restart dovecot

        echo "OK"
        ;;
    *)
        echo "Operação desconhecida: $OPERATION" >&2
        exit 1
        ;;
esac

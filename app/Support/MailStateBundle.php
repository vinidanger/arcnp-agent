<?php

namespace App\Support;

/**
 * Empacota os 5 arquivos que o Postfix/Dovecot precisam num único
 * texto (seções delimitadas por "===NOME==="), pra sincronizar tudo
 * numa chamada só ao script sudo — mesma ideia do zones.conf do DNS,
 * só que com múltiplos arquivos de uma vez porque Postfix e Dovecot
 * cada um tem seu próprio formato de mapeamento pro mesmo dado.
 *
 * @param list<string> $domains
 * @param list<array{email: string, username: string, domain: string, local_part: string, password_hash: string, uid: int, gid: int}> $mailboxes
 */
class MailStateBundle
{
    public static function render(array $domains, array $mailboxes): string
    {
        $virtualDomains = implode("\n", array_map(fn (string $d) => "{$d} OK", $domains));

        $mailboxMaps = [];
        $uidMaps = [];
        $gidMaps = [];
        $dovecotUsers = [];

        foreach ($mailboxes as $mailbox) {
            $home = "/home/{$mailbox['username']}/mail/{$mailbox['domain']}/{$mailbox['local_part']}";
            $maildirRelative = "{$mailbox['username']}/mail/{$mailbox['domain']}/{$mailbox['local_part']}/Maildir/";

            $mailboxMaps[] = "{$mailbox['email']} {$maildirRelative}";
            $uidMaps[] = "{$mailbox['email']} {$mailbox['uid']}";
            $gidMaps[] = "{$mailbox['email']} {$mailbox['gid']}";
            $dovecotUsers[] = "{$mailbox['email']}:{$mailbox['password_hash']}:{$mailbox['uid']}:{$mailbox['gid']}::{$home}::userdb_mail=maildir:{$home}/Maildir";
        }

        return self::section('POSTFIX_VIRTUAL_DOMAINS', $virtualDomains)
            .self::section('POSTFIX_VIRTUAL_MAILBOX_MAPS', implode("\n", $mailboxMaps))
            .self::section('POSTFIX_VIRTUAL_UID_MAPS', implode("\n", $uidMaps))
            .self::section('POSTFIX_VIRTUAL_GID_MAPS', implode("\n", $gidMaps))
            .self::section('DOVECOT_USERS', implode("\n", $dovecotUsers))
            ."===END===\n";
    }

    private static function section(string $name, string $content): string
    {
        return "==={$name}===\n{$content}\n";
    }
}

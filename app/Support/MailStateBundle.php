<?php

namespace App\Support;

/**
 * Empacota os arquivos que o Postfix/Dovecot precisam num único texto
 * (seções delimitadas por "===NOME==="), pra sincronizar tudo numa
 * chamada só ao script sudo — mesma ideia do zones.conf do DNS, só que
 * com múltiplos arquivos de uma vez porque Postfix e Dovecot cada um
 * tem seu próprio formato de mapeamento pro mesmo dado.
 *
 * @param list<string> $domains
 * @param list<array{email: string, username: string, domain: string, local_part: string, password_hash: string, uid: int, gid: int}> $mailboxes
 * @param list<array{source: string, destination: string}> $forwarders
 * @param list<array{email: string, username: string, domain: string, local_part: string, uid: int, gid: int, subject: string, message: string}> $vacations
 */
class MailStateBundle
{
    public static function render(array $domains, array $mailboxes, array $forwarders = [], array $vacations = []): string
    {
        $virtualDomains = implode("\n", array_map(fn (string $d) => "{$d} OK", $domains));

        $mailboxMaps = [];
        $uidMaps = [];
        $gidMaps = [];
        $dovecotUsers = [];

        foreach ($mailboxes as $mailbox) {
            $home = self::mailboxHome($mailbox);
            $maildirRelative = "{$mailbox['username']}/mail/{$mailbox['domain']}/{$mailbox['local_part']}/Maildir/";

            $mailboxMaps[] = "{$mailbox['email']} {$maildirRelative}";
            $uidMaps[] = "{$mailbox['email']} {$mailbox['uid']}";
            $gidMaps[] = "{$mailbox['email']} {$mailbox['gid']}";
            $dovecotUsers[] = "{$mailbox['email']}:{$mailbox['password_hash']}:{$mailbox['uid']}:{$mailbox['gid']}::{$home}::userdb_mail=maildir:{$home}/Maildir";
        }

        // Encaminhamento puro (sem caixa própria) precisa de um mapa
        // Postfix SEPARADO de virtual_mailbox_maps — esse último exige
        // uma caixa real por trás (Maildir/UID/GID), então um alias que
        // só redireciona pra outro endereço não pode entrar nele.
        $aliasMaps = array_map(fn ($f) => "{$f['source']} {$f['destination']}", $forwarders);

        // Cada linha é "home:uid:gid:script_base64" — base64 nunca tem
        // ":", então dá pra usar como delimitador sem ambiguidade. Só
        // entram aqui as caixas com aviso de férias HABILITADO; o
        // manage-mail.sh apaga o .sieve de quem não aparecer nesta
        // seção (senão desativar no Painel não pararia o autoresposta,
        // já que o Dovecot só olha se o arquivo existe no disco).
        $sieveVacations = array_map(function (array $vacation) {
            $home = self::mailboxHome($vacation);
            $script = base64_encode(SieveVacation::render($vacation['subject'], $vacation['message']));

            return "{$home}:{$vacation['uid']}:{$vacation['gid']}:{$script}";
        }, $vacations);

        return self::section('POSTFIX_VIRTUAL_DOMAINS', $virtualDomains)
            .self::section('POSTFIX_VIRTUAL_MAILBOX_MAPS', implode("\n", $mailboxMaps))
            .self::section('POSTFIX_VIRTUAL_ALIAS_MAPS', implode("\n", $aliasMaps))
            .self::section('POSTFIX_VIRTUAL_UID_MAPS', implode("\n", $uidMaps))
            .self::section('POSTFIX_VIRTUAL_GID_MAPS', implode("\n", $gidMaps))
            .self::section('DOVECOT_USERS', implode("\n", $dovecotUsers))
            .self::section('SIEVE_VACATION', implode("\n", $sieveVacations))
            ."===END===\n";
    }

    /** @param array{username: string, domain: string, local_part: string} $entry */
    private static function mailboxHome(array $entry): string
    {
        return "/home/{$entry['username']}/mail/{$entry['domain']}/{$entry['local_part']}";
    }

    private static function section(string $name, string $content): string
    {
        return "==={$name}===\n{$content}\n";
    }
}

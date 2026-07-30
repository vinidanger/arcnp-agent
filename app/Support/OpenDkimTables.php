<?php

namespace App\Support;

/**
 * Seletor DKIM fixo ("mail") pra todo domínio — não precisa ser
 * configurável, só precisa ser consistente entre a chave gerada
 * (manage-mail-dkim.sh) e o registro TXT publicado no DNS
 * (mail._domainkey.dominio).
 */
class OpenDkimTables
{
    public const SELECTOR = 'mail';

    /** @param list<string> $domains */
    public static function keyTable(array $domains): string
    {
        $lines = array_map(
            fn (string $domain) => self::SELECTOR."._domainkey.{$domain} {$domain}:".self::SELECTOR.':/etc/opendkim/keys/'.$domain.'/'.self::SELECTOR.'.private',
            $domains
        );

        return implode("\n", $lines)."\n";
    }

    /** @param list<string> $domains */
    public static function signingTable(array $domains): string
    {
        $lines = array_map(
            fn (string $domain) => "*@{$domain} ".self::SELECTOR."._domainkey.{$domain}",
            $domains
        );

        return implode("\n", $lines)."\n";
    }

    /** @param list<string> $domains */
    public static function bundle(array $domains): string
    {
        return "===KEYTABLE===\n".self::keyTable($domains)
            ."===SIGNINGTABLE===\n".self::signingTable($domains)
            ."===END===\n";
    }
}

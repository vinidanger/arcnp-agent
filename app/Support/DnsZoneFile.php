<?php

namespace App\Support;

/**
 * Monta o conteúdo textual de um zone file BIND a partir da lista de
 * registros já validada (DnsRecordValidator) — o Painel é sempre a
 * fonte da verdade, isto aqui só formata. Serial = timestamp Unix:
 * sempre crescente entre regravações, mais simples que gerenciar
 * contador no formato YYYYMMDDNN tradicional e serve exatamente pro
 * mesmo propósito (secundário/cache perceber que mudou).
 */
class DnsZoneFile
{
    /**
     * @param  list<string>  $nsHosts
     * @param  list<array{type: string, name: string, content: string, ttl: int, priority: ?int}>  $records
     */
    public static function render(string $domain, array $nsHosts, string $adminEmail, array $records): string
    {
        $serial = time();
        $soaEmail = str_replace('@', '.', $adminEmail);
        $soaEmail = rtrim($soaEmail, '.').'.';

        $lines = [];
        $lines[] = '$TTL 3600';
        $lines[] = "@\tIN\tSOA\t".self::fqdn($nsHosts[0] ?? 'ns1.'.$domain)."\t{$soaEmail} (";
        $lines[] = "\t\t\t{$serial}\t; serial";
        $lines[] = "\t\t\t3600\t\t; refresh";
        $lines[] = "\t\t\t900\t\t; retry";
        $lines[] = "\t\t\t1209600\t\t; expire";
        $lines[] = "\t\t\t3600 )\t\t; minimum";
        $lines[] = '';

        foreach ($nsHosts as $ns) {
            $lines[] = "@\tIN\tNS\t".self::fqdn($ns);
        }

        $lines[] = '';

        foreach ($records as $record) {
            $lines[] = self::renderRecord($record);
        }

        return implode("\n", $lines)."\n";
    }

    private static function renderRecord(array $record): string
    {
        $name = $record['name'] === '' ? '@' : $record['name'];
        $ttl = $record['ttl'];
        $type = $record['type'];

        return match ($type) {
            'A', 'AAAA' => "{$name}\t{$ttl}\tIN\t{$type}\t{$record['content']}",
            'CNAME', 'NS' => "{$name}\t{$ttl}\tIN\t{$type}\t".self::fqdn($record['content']),
            'MX' => "{$name}\t{$ttl}\tIN\tMX\t{$record['priority']}\t".self::fqdn($record['content']),
            'TXT' => "{$name}\t{$ttl}\tIN\tTXT\t".self::txtChunks($record['content']),
            default => '',
        };
    }

    /**
     * Um "character-string" do DNS não passa de 255 bytes no formato de
     * fio — valor mais longo que isso (chave DKIM, por exemplo) precisa
     * virar várias strings entre aspas concatenadas, não uma só. BIND
     * junta tudo de volta como um único valor lógico de TXT na hora de
     * responder a consulta.
     */
    private static function txtChunks(string $content): string
    {
        $escaped = str_replace('"', '\\"', $content);

        // str_split (não mb_str_split): o limite de 255 é em BYTES no
        // formato de fio do DNS, não em caracteres.
        $chunks = str_split($escaped, 255);

        return implode(' ', array_map(fn (string $chunk) => "\"{$chunk}\"", $chunks));
    }

    private static function fqdn(string $host): string
    {
        if ($host === '@' || str_ends_with($host, '.')) {
            return $host;
        }

        return "{$host}.";
    }
}

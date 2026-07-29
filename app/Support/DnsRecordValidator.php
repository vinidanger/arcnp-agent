<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Valida e normaliza um registro DNS antes de virar linha de zone
 * file — cada tipo tem sua própria regra de "content" (IP pra A/AAAA,
 * hostname pra CNAME/MX/NS, texto livre pra TXT).
 */
class DnsRecordValidator
{
    private const NAME_PATTERN = '/^(@|[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*)$/';

    private const HOSTNAME_PATTERN = '/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+\.?$/';

    private const TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS'];

    public static function validate(array $record): array
    {
        $type = strtoupper((string) ($record['type'] ?? ''));

        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException("Tipo de registro inválido: {$type}");
        }

        $name = strtolower((string) ($record['name'] ?? '@'));

        if (! preg_match(self::NAME_PATTERN, $name)) {
            throw new InvalidArgumentException("Nome de registro inválido: {$name}");
        }

        $content = trim((string) ($record['content'] ?? ''));
        $ttl = (int) ($record['ttl'] ?? 3600);

        if ($ttl < 60 || $ttl > 604800) {
            throw new InvalidArgumentException("TTL inválido: {$ttl}");
        }

        $priority = null;

        match ($type) {
            'A' => self::validateIp($content, false),
            'AAAA' => self::validateIp($content, true),
            'CNAME', 'NS' => self::validateHostname($content),
            'MX' => [
                self::validateHostname($content),
                $priority = self::validatePriority($record['priority'] ?? null),
            ],
            'TXT' => self::validateText($content),
        };

        return [
            'type' => $type,
            'name' => $name,
            'content' => $content,
            'ttl' => $ttl,
            'priority' => $priority,
        ];
    }

    private static function validateIp(string $value, bool $v6): void
    {
        $flag = $v6 ? FILTER_FLAG_IPV6 : FILTER_FLAG_IPV4;

        if (! filter_var($value, FILTER_VALIDATE_IP, $flag)) {
            throw new InvalidArgumentException("IP inválido: {$value}");
        }
    }

    private static function validateHostname(string $value): void
    {
        if ($value === '@') {
            return;
        }

        if (! preg_match(self::HOSTNAME_PATTERN, $value)) {
            throw new InvalidArgumentException("Hostname inválido: {$value}");
        }
    }

    private static function validatePriority(mixed $value): int
    {
        if (! is_numeric($value) || (int) $value < 0 || (int) $value > 65535) {
            throw new InvalidArgumentException('Prioridade MX inválida.');
        }

        return (int) $value;
    }

    private static function validateText(string $value): void
    {
        if ($value === '' || strlen($value) > 255 || str_contains($value, "\n") || str_contains($value, "\r")) {
            throw new InvalidArgumentException('Conteúdo TXT inválido.');
        }
    }
}

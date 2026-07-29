<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Valida os 5 campos de agendamento cron (aceita a sintaxe padrão:
 * dígitos, *, vírgula, hífen, barra — sem nomes de mês/dia por
 * extenso, sem @reboot/@daily etc., pra manter a validação simples e
 * whitelist-based) e formata a linha final pro arquivo em
 * /etc/cron.d/, incluindo o campo de usuário que o cron.d exige.
 */
class CronSchedule
{
    private const FIELD_PATTERN = '/^[0-9*,\-\/]+$/';

    public static function validateField(string $value): string
    {
        if (! preg_match(self::FIELD_PATTERN, $value)) {
            throw new InvalidArgumentException("Campo de agendamento inválido: {$value}");
        }

        return $value;
    }

    public static function validateCommand(string $command): string
    {
        $command = trim($command);

        if ($command === '' || str_contains($command, "\n") || str_contains($command, "\r")) {
            throw new InvalidArgumentException('Comando de cron inválido.');
        }

        return $command;
    }

    public static function formatLine(string $username, array $job): string
    {
        $fields = array_map(
            self::validateField(...),
            [$job['minute'], $job['hour'], $job['day'], $job['month'], $job['weekday']]
        );

        $command = self::validateCommand($job['command']);

        return implode(' ', [...$fields, $username, $command]);
    }
}

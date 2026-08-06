<?php

namespace App\Actions\Security;

use App\Actions\Contracts\AgentAction;
use App\Services\System\ProcessRunner;
use InvalidArgumentException;

class UnbanIpAction implements AgentAction
{
    private const KNOWN_JAILS = ['sshd', 'vsftpd'];

    public function __construct(private ProcessRunner $processRunner)
    {
    }

    public function isAsync(): bool
    {
        return false;
    }

    public function execute(array $payload): array
    {
        $jail = (string) ($payload['jail'] ?? '');
        $ip = (string) ($payload['ip'] ?? '');

        if (! in_array($jail, self::KNOWN_JAILS, true)) {
            throw new InvalidArgumentException("Jail inválido: {$jail}");
        }

        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            throw new InvalidArgumentException("IP inválido: {$ip}");
        }

        $this->processRunner->unbanIp($jail, $ip);

        return ['jail' => $jail, 'ip' => $ip, 'unbanned' => true];
    }
}

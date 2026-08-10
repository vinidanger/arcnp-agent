<?php

namespace App\Actions\Server;

use App\Actions\Contracts\AgentAction;
use App\Services\System\ProcessRunner;

/**
 * Lista fixa de units conhecidos (levantada olhando o próprio deploy/
 * README.md do Agent) — nunca vem do Painel, então "systemctl is-active"
 * nunca recebe nome de unit vindo de fora. O nome do serviço de banco
 * (mysqld/mariadb, varia conforme o que foi instalado manualmente) vem
 * do payload porque é o único que genuinamente não é fixo — mas ainda
 * assim é validado contra um formato de nome de unit antes de entrar
 * na lista, nunca passado livre pro systemctl.
 */
class CollectServerInfoAction implements AgentAction
{
    /**
     * "php-fpm-hosting"/"php81-php-fpm"/"php82-php-fpm"/"php84-php-fpm"
     * já não fazem mais parte daqui — eram a arquitetura antiga de
     * PHP-FPM (um service global por versão), substituída pela
     * arquitetura por-conta (arcnp-php-{usuário}-{grupo}.service, ver
     * seção 40 do deploy/README.md). Não dá pra monitorar esses
     * dinamicamente (nascem/somem por conta), então PHP simplesmente
     * não aparece nesta tela — confirme uma conta específica olhando
     * "systemctl status arcnp-php-{usuário}-*" direto no servidor.
     */
    private const KNOWN_UNITS = [
        'nginx',
        'postfix',
        'dovecot',
        'opendkim',
        'named',
        'vsftpd',
        'ttyd',
        'arcnp-agent-queue',
        'fail2ban',
        'clamav-freshclam.service',
    ];

    public function __construct(private ProcessRunner $processRunner)
    {
    }

    public function isAsync(): bool
    {
        return false;
    }

    public function execute(array $payload): array
    {
        $units = self::KNOWN_UNITS;

        $mysqlServiceName = (string) ($payload['mysql_service_name'] ?? '');

        if ($mysqlServiceName !== '' && preg_match('/^[a-zA-Z0-9_.-]+$/', $mysqlServiceName)) {
            $units[] = $mysqlServiceName;
        }

        return [
            'system' => $this->processRunner->collectSystemInfo(),
            'services' => $this->processRunner->serviceStatuses($units),
            'service_versions' => $this->processRunner->serviceVersions($units, $mysqlServiceName ?: null),
        ];
    }
}

<?php

namespace App\Actions\Php;

use App\Actions\Contracts\AgentAction;
use App\Services\System\ProcessRunner;
use App\Support\PhpExtensionList;
use App\Support\PhpVersion;
use InvalidArgumentException;

/**
 * Só alterna um arquivo .ini que JÁ EXISTE (habilitado ou desabilitado)
 * — nunca cria/instala extensão nova, isso continua sendo uma operação
 * de SERVIDOR feita pelo admin via SSH (dnf install), documentada no
 * deploy/README.md. Reload do PHP-FPM daquela versão no final, senão a
 * mudança só valeria depois do próximo restart.
 */
class TogglePhpExtensionAction implements AgentAction
{
    public function __construct(private ProcessRunner $processRunner)
    {
    }

    public function isAsync(): bool
    {
        return false;
    }

    public function execute(array $payload): array
    {
        $phpVersion = (string) ($payload['php_version'] ?? '');
        $filename = (string) ($payload['filename'] ?? '');
        $enable = (bool) ($payload['enable'] ?? false);

        $config = PhpVersion::config($phpVersion);

        // Defesa em profundidade — só mexe num arquivo que realmente
        // está na lista atual daquela versão (nunca confia só na
        // validação do Painel).
        $current = PhpExtensionList::forVersion($phpVersion);
        $match = null;

        foreach ($current as $extension) {
            if ($extension['filename'] === $filename) {
                $match = $extension;
                break;
            }
        }

        if (! $match) {
            throw new InvalidArgumentException("Extensão não encontrada: {$filename}");
        }

        if ($match['enabled'] === $enable) {
            return ['filename' => $filename, 'enabled' => $enable, 'changed' => false];
        }

        $this->processRunner->togglePhpExtension($config['ini_dir'], $filename, $enable);
        // Sem 1 service compartilhado por versão pra recarregar (cada
        // conta tem o próprio processo PHP-FPM agora) — precisa
        // reiniciar todo unit de conta que usa esse binário.
        $this->processRunner->reloadPhpFpmServicesForBinary($config['binary']);

        return ['filename' => $filename, 'enabled' => $enable, 'changed' => true];
    }
}

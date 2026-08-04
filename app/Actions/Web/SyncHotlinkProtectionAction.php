<?php

namespace App\Actions\Web;

use App\Actions\Contracts\AgentAction;
use App\Services\System\ProcessRunner;
use App\Support\DomainName;
use App\Support\NginxVhost;
use App\Support\VhostExtraBlock;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use RuntimeException;

/**
 * Mesmo mecanismo de bloco-entre-marcadores da proteção de pasta e dos
 * redirecionamentos (ver VhostExtraBlock) — aqui o bloco é um único
 * "location" com "valid_referers" cobrindo as extensões escolhidas,
 * já que hotlink é liga/desliga por domínio, não uma lista de regras.
 */
class SyncHotlinkProtectionAction implements AgentAction
{
    private const MARKER_BEGIN = '# ARCNP:HOTLINK:BEGIN';

    private const MARKER_END = '# ARCNP:HOTLINK:END';

    public function __construct(private ProcessRunner $processRunner)
    {
    }

    public function isAsync(): bool
    {
        return false;
    }

    public function execute(array $payload): array
    {
        $domain = DomainName::validate($payload['domain'] ?? '');
        $configPath = NginxVhost::configPath($domain);

        if (! File::exists($configPath)) {
            throw new RuntimeException("Vhost não encontrado para: {$domain}");
        }

        $original = File::get($configPath);
        $hasMarkers = str_contains($original, self::MARKER_BEGIN);
        $enabled = (bool) ($payload['enabled'] ?? false);

        if (! $enabled && ! $hasMarkers) {
            return ['domain' => $domain, 'enabled' => false];
        }

        $block = $enabled ? $this->renderBlock($domain, $payload) : '';

        $updated = VhostExtraBlock::replace($original, self::MARKER_BEGIN, self::MARKER_END, $block);

        File::put($configPath, $updated);

        try {
            $this->processRunner->testNginxConfig();
            $this->processRunner->reloadNginx();
        } catch (\Throwable $e) {
            File::put($configPath, $original);
            throw $e;
        }

        return ['domain' => $domain, 'enabled' => $enabled];
    }

    private function renderBlock(string $domain, array $payload): string
    {
        $extensions = array_map(fn ($e) => $this->validateExtension((string) $e), $payload['extensions'] ?? []);

        if ($extensions === []) {
            throw new InvalidArgumentException('Nenhuma extensão de arquivo informada.');
        }

        $referrers = array_map(fn ($r) => $this->validateReferrer((string) $r), $payload['allowed_referrers'] ?? []);

        $validReferers = implode(' ', array_merge(['none', 'blocked', $domain, "*.{$domain}"], $referrers));
        $extPattern = implode('|', $extensions);

        return implode("\n", [
            "    location ~* \\.({$extPattern})\$ {",
            "        valid_referers {$validReferers};",
            '        if ($invalid_referer) {',
            '            return 403;',
            '        }',
            '    }',
        ]);
    }

    private function validateExtension(string $ext): string
    {
        $ext = strtolower(trim($ext));

        // Entra direto numa alternativa de regex do nginx
        // ("~* \.(ext1|ext2)$") — só alfanumérico, sem exceção.
        if (! preg_match('/^[a-z0-9]+$/', $ext)) {
            throw new InvalidArgumentException("Extensão inválida: {$ext}");
        }

        return $ext;
    }

    private function validateReferrer(string $referrer): string
    {
        $referrer = trim($referrer);

        // Entra direto na diretiva "valid_referers" — aceita o "*." de
        // prefixo curinga que o próprio nginx entende, mais nada.
        if (! preg_match('/^\*?[a-zA-Z0-9.\-]+$/', $referrer)) {
            throw new InvalidArgumentException("Domínio de referência inválido: {$referrer}");
        }

        return $referrer;
    }
}

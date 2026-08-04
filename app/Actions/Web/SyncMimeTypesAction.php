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
 * Mesmo mecanismo de bloco-entre-marcadores da proteção de pasta/
 * redirecionamentos (ver VhostExtraBlock) — o bloco aqui é um "types { }"
 * dentro do server{}. IMPORTANTE: um bloco "types{}" num contexto mais
 * específico (server) SUBSTITUI por completo o herdado do http{} — não
 * é cumulativo. Por isso o bloco sempre começa com "include mime.types;"
 * (resolve contra o prefix padrão do nginx, /etc/nginx/mime.types,
 * mesmo caminho relativo usado no nginx.conf raiz) antes de acrescentar
 * as extensões customizadas — sem isso, css/js/html/imagens do domínio
 * inteiro quebrariam (virariam application/octet-stream) assim que
 * qualquer regra customizada fosse adicionada.
 */
class SyncMimeTypesAction implements AgentAction
{
    private const MARKER_BEGIN = '# ARCNP:MIME-TYPES:BEGIN';

    private const MARKER_END = '# ARCNP:MIME-TYPES:END';

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

        $rules = array_map(fn (array $r) => $this->validateRule($r), $payload['rules'] ?? []);

        if ($rules === [] && ! $hasMarkers) {
            return ['domain' => $domain, 'rules' => 0];
        }

        $updated = VhostExtraBlock::replace($original, self::MARKER_BEGIN, self::MARKER_END, $this->renderBlock($rules));

        File::put($configPath, $updated);

        try {
            $this->processRunner->testNginxConfig();
            $this->processRunner->reloadNginx();
        } catch (\Throwable $e) {
            File::put($configPath, $original);
            throw $e;
        }

        return ['domain' => $domain, 'rules' => count($rules)];
    }

    private function validateRule(array $r): array
    {
        $extension = (string) ($r['extension'] ?? '');
        $mimeType = (string) ($r['mime_type'] ?? '');

        // Path já vem validado no Painel — confere de novo por defesa
        // em profundidade, já que entra direto num bloco "types{}" do
        // nginx. Extensão: só letras/números/ponto (pra permitir algo
        // tipo "tar.gz"). Tipo MIME: formato padrão "categoria/subtipo",
        // com os caracteres que RFC 6838 permite num subtipo.
        if (! preg_match('/^[a-zA-Z0-9.]+$/', $extension)) {
            throw new InvalidArgumentException("Extensão inválida: {$extension}");
        }

        if (! preg_match('~^[a-zA-Z0-9][a-zA-Z0-9!#$&.+\-^_]*/[a-zA-Z0-9][a-zA-Z0-9!#$&.+\-^_]*$~', $mimeType)) {
            throw new InvalidArgumentException("Tipo MIME inválido: {$mimeType}");
        }

        return compact('extension', 'mimeType');
    }

    /** @param list<array{extension: string, mimeType: string}> $rules */
    private function renderBlock(array $rules): string
    {
        if ($rules === []) {
            return '';
        }

        $lines = ['    types {', '        include mime.types;'];

        foreach ($rules as $r) {
            $lines[] = "        {$r['mimeType']} {$r['extension']};";
        }

        $lines[] = '    }';

        return implode("\n", $lines);
    }
}

<?php

namespace App\Actions\Web;

use App\Actions\Contracts\AgentAction;
use App\Services\System\ProcessRunner;
use App\Support\DomainName;

/**
 * Lê o access.log inteiro do domínio (formato "combined" padrão do
 * nginx, sem log_format customizado — ver seção do README) e devolve
 * só o AGREGADO (total de hits, contagem de IPs únicos, top caminhos,
 * contagem por status) — nunca o IP de cada visitante nem qualquer
 * linha crua, por privacidade e pra não inflar a resposta.
 *
 * Pensado pra rodar 1x/dia, ANTES da rotação de log configurada no
 * deploy (ver seção nova do README) — sem isso, o arquivo pode ter
 * sido truncado/renomeado no meio da leitura.
 */
class AnalyzeDomainTrafficAction implements AgentAction
{
    private const TOP_PATHS_LIMIT = 10;

    /**
     * Formato combined do nginx:
     * $remote_addr - $remote_user [$time_local] "$request" $status $body_bytes_sent "$http_referer" "$http_user_agent"
     */
    private const LOG_LINE_PATTERN = '/^(?<ip>\S+) \S+ \S+ \[[^\]]+\] "(?<method>\S+) (?<path>\S+)[^"]*" (?<status>\d{3}) \d+/';

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

        $content = $this->processRunner->readFullAccessLog($domain);

        $hits = 0;
        $ips = [];
        $paths = [];
        $statuses = [];

        foreach (preg_split('/\r?\n/', $content) as $line) {
            if ($line === '') {
                continue;
            }

            if (! preg_match(self::LOG_LINE_PATTERN, $line, $match)) {
                continue;
            }

            // Sem a query string: "/index.php?utm_source=fb" e
            // "/index.php?utm_source=google" são a MESMA página pra
            // fins de "top páginas" — sem isso, cada combinação de
            // parâmetro (rastreamento, cache-buster, etc.) fragmentaria
            // a lista e a tornaria inútil.
            $path = strtok($match['path'], '?');

            $hits++;
            $ips[$match['ip']] = true;
            $paths[$path] = ($paths[$path] ?? 0) + 1;
            $statuses[$match['status']] = ($statuses[$match['status']] ?? 0) + 1;
        }

        arsort($paths);
        $topPaths = [];

        foreach (array_slice($paths, 0, self::TOP_PATHS_LIMIT, true) as $path => $count) {
            $topPaths[] = ['path' => $path, 'hits' => $count];
        }

        return [
            'domain' => $domain,
            'hits' => $hits,
            'unique_visitors' => count($ips),
            'top_paths' => $topPaths,
            'status_counts' => $statuses,
        ];
    }
}

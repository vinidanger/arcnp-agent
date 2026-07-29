<?php

namespace App\Console\Commands;

use App\Services\PanelCallbackClient;
use Illuminate\Console\Command;

class AgentHeartbeatCommand extends Command
{
    protected $signature = 'agent:heartbeat';

    protected $description = 'Envia ao Painel um snapshot de status/métricas deste servidor.';

    public function handle(PanelCallbackClient $client): int
    {
        $metrics = $this->collectMetrics();

        $ok = $client->sendHeartbeat($metrics);

        $this->info($ok ? 'Heartbeat enviado.' : 'Falha ao enviar heartbeat (ver log).');

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Coleta best-effort — métricas indisponíveis (ex.: rodando fora de
     * Linux, em dev) vêm como null em vez de quebrar o heartbeat.
     */
    private function collectMetrics(): array
    {
        $load = function_exists('sys_getloadavg') ? sys_getloadavg() : false;

        $diskPercent = null;
        $diskPath = PHP_OS_FAMILY === 'Windows' ? getcwd() : '/';

        if (($total = @disk_total_space($diskPath)) && ($free = @disk_free_space($diskPath))) {
            $diskPercent = round((($total - $free) / $total) * 100, 1);
        }

        $memPercent = $this->readLinuxMemoryPercent();

        return [
            'load_avg' => $load ? round($load[0], 2) : null,
            'disk_percent' => $diskPercent,
            'mem_percent' => $memPercent,
            'checked_at' => now()->toIso8601String(),
        ];
    }

    private function readLinuxMemoryPercent(): ?float
    {
        if (! is_readable('/proc/meminfo')) {
            return null;
        }

        $lines = file('/proc/meminfo');
        $values = [];

        foreach ($lines as $line) {
            if (preg_match('/^(MemTotal|MemAvailable):\s+(\d+)/', $line, $m)) {
                $values[$m[1]] = (float) $m[2];
            }
        }

        if (empty($values['MemTotal']) || ! isset($values['MemAvailable'])) {
            return null;
        }

        return round((($values['MemTotal'] - $values['MemAvailable']) / $values['MemTotal']) * 100, 1);
    }
}

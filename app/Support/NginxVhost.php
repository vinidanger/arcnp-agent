<?php

namespace App\Support;

use App\Services\System\ProcessRunner;
use Illuminate\Support\Facades\File;
use Throwable;

class NginxVhost
{
    public static function configPath(string $domain): string
    {
        return config('provisioning.nginx_conf_dir')."/{$domain}.conf";
    }

    /**
     * Escreve o vhost e só recarrega o nginx se "nginx -t" passar. Se o
     * teste falhar, restaura o conteúdo anterior do arquivo (ou apaga,
     * se ele não existia antes) antes de propagar o erro — sem isso, um
     * teste que falha deixa o arquivo QUEBRADO em disco mesmo sem
     * nenhum reload ter acontecido, e como "nginx -t" valida a config
     * inteira (não só esse arquivo), isso pode derrubar TODOS os
     * domínios do servidor no próximo reload de qualquer causa (achado
     * real em produção: WAF habilitado num domínio antes do módulo
     * ModSecurity estar instalado no nginx).
     */
    public static function writeTested(string $path, string $contents, ProcessRunner $processRunner): void
    {
        $existed = File::exists($path);
        $previousContents = $existed ? File::get($path) : null;

        File::put($path, $contents);

        try {
            $processRunner->testNginxConfig();
        } catch (Throwable $e) {
            if ($existed) {
                File::put($path, $previousContents);
            } else {
                File::delete($path);
            }

            throw $e;
        }

        $processRunner->reloadNginx();
    }
}

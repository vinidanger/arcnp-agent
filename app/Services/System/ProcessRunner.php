<?php

namespace App\Services\System;

use App\Support\PhpVersion;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Único ponto do Agent que invoca comandos privilegiados. Cada método
 * é um comando de forma fixa (sem concatenação de string em shell,
 * args sempre em array) e corresponde 1:1 a uma linha do sudoers
 * (ver deploy/sudoers/arcnp-agent). Nenhuma Action monta comando livre —
 * só chama estes métodos nomeados.
 *
 * Criação/remoção de usuário passa por um script wrapper (scripts/*.sh)
 * em vez de useradd/userdel direto, porque o script também prepara e
 * ajusta a posse de public_html/logs — o Agent (usuário sem privilégio)
 * não teria permissão de escrever dentro do home dir recém-criado
 * (dono é o novo usuário, não o arcnpagent) sem isso.
 */
class ProcessRunner
{
    public function createHostingUser(string $username): void
    {
        $this->exec(['sudo', '-n', base_path('scripts/create-hosting-user.sh'), $username]);
    }

    public function deleteHostingUser(string $username): void
    {
        $this->exec(['sudo', '-n', base_path('scripts/delete-hosting-user.sh'), $username]);
    }

    public function testNginxConfig(): void
    {
        $this->exec(['sudo', '-n', '/usr/sbin/nginx', '-t']);
    }

    public function reloadNginx(): void
    {
        $this->exec(['sudo', '-n', '/usr/bin/systemctl', 'reload', 'nginx']);
    }

    /**
     * Recarrega o php-fpm.service dessa versão específica — cada versão
     * é isolada num serviço próprio (ver config/provisioning.php), então
     * reload de um pool nunca afeta contas de outra versão nem o
     * Painel/Agent.
     */
    public function reloadPhpFpm(string $phpVersion): void
    {
        $this->exec(['sudo', '-n', '/usr/bin/systemctl', 'reload', PhpVersion::config($phpVersion)['service']]);
    }

    public function userExists(string $username): bool
    {
        return Process::run(['id', '-u', $username])->successful();
    }

    public function createAddonDirectory(string $username, string $subdir): void
    {
        $this->exec(['sudo', '-n', base_path('scripts/create-addon-directory.sh'), $username, $subdir]);
    }

    public function deleteAddonDirectory(string $username, string $subdir): void
    {
        $this->exec(['sudo', '-n', base_path('scripts/delete-addon-directory.sh'), $username, $subdir]);
    }

    /**
     * Certbot/Let's Encrypt envolve chamada de rede externa — timeout
     * bem maior que os outros comandos (e por isso essa Action é
     * assíncrona no Agent, não roda inline na resposta HTTP).
     */
    public function issueSslCertificate(string $domain, string $webroot): void
    {
        $this->exec([
            'sudo', '-n', base_path('scripts/issue-ssl-certificate.sh'),
            $domain, $webroot, config('provisioning.ssl_admin_email'),
        ], 120);
    }

    private function exec(array $command, int $timeout = 30): void
    {
        $result = Process::timeout($timeout)->run($command);

        if ($result->failed()) {
            throw new RuntimeException(
                'Comando falhou ('.implode(' ', $command).'): '.trim($result->errorOutput() ?: $result->output())
            );
        }
    }
}

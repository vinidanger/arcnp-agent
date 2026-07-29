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

    /**
     * Dump sem privilégio (o próprio Agent já tem acesso de leitura ao
     * MySQL via a conexão mysql_admin) — grava comprimido direto no
     * destino. Senha via env MYSQL_PWD em vez de -p na linha de
     * comando, pra não aparecer em `ps`. Buffer inteiro em memória
     * antes de comprimir: aceitável pro tamanho de conta que esse
     * painel atende; se algum dia isso virar gargalo, trocar por um
     * pipe real mysqldump|gzip.
     */
    public function dumpMysqlDatabase(string $dbName, string $destinationPath): void
    {
        $config = config('database.connections.mysql_admin');

        $result = Process::timeout(300)
            ->env(['MYSQL_PWD' => $config['password']])
            ->run([
                'mysqldump',
                '--user='.$config['username'],
                '--host='.($config['host'] ?? '127.0.0.1'),
                '--single-transaction',
                '--quick',
                $dbName,
            ]);

        if ($result->failed()) {
            throw new RuntimeException('mysqldump falhou ('.$dbName.'): '.trim($result->errorOutput()));
        }

        file_put_contents($destinationPath, gzencode($result->output(), 6));
    }

    /**
     * @param  list<string>  $databases
     * @return list<array{filename: string, size: int}>
     */
    public function createBackup(string $username, int $retention, string $tmpDumpDir, array $databases): array
    {
        $result = Process::timeout(600)->run([
            'sudo', '-n', base_path('scripts/create-backup.sh'),
            $username, (string) $retention, $tmpDumpDir,
            ...$databases,
        ]);

        if ($result->failed()) {
            throw new RuntimeException(
                'Backup falhou: '.trim($result->errorOutput() ?: $result->output())
            );
        }

        return json_decode($result->output(), true) ?? [];
    }

    public function diskUsageBytes(string $username): int
    {
        $result = Process::timeout(60)->run([
            'sudo', '-n', base_path('scripts/disk-usage.sh'), $username,
        ]);

        if ($result->failed()) {
            throw new RuntimeException(
                'Cálculo de uso de disco falhou: '.trim($result->errorOutput() ?: $result->output())
            );
        }

        return (int) trim($result->output());
    }

    /**
     * Único ponto que muta arquivos dentro de public_html (criar,
     * salvar, apagar, renomear) — listar/ler não passam por aqui, o
     * Agent já tem ACL de leitura direto (ver FileManagerPath). $content
     * vai pro STDIN do script (nunca argv — arquivo pode ser grande e
     * argv tem limite de tamanho do SO).
     */
    public function manageFile(string $username, string $operation, string $path, ?string $path2 = null, ?string $content = null): void
    {
        $command = ['sudo', '-n', base_path('scripts/manage-file.sh'), $username, $operation, $path];

        if ($path2 !== null) {
            $command[] = $path2;
        }

        $process = Process::timeout(30);

        if ($content !== null) {
            $process = $process->input($content);
        }

        $result = $process->run($command);

        if ($result->failed()) {
            throw new RuntimeException(
                'Operação de arquivo falhou ('.$operation.'): '.trim($result->errorOutput() ?: $result->output())
            );
        }
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

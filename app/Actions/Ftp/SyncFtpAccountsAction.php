<?php

namespace App\Actions\Ftp;

use App\Actions\Contracts\AgentAction;
use App\Support\FtpChrootPath;
use App\Support\LinuxUsername;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use RuntimeException;

/**
 * Sem sudo, de propósito — mesmo espírito do CreateVirtualHostAction:
 * os diretórios de saída (ftp_virtual_users_dir/ftp_user_conf_dir) são
 * group-writable pro usuário do Agent (ver deploy/README.md seção 33),
 * então nem o db_load nem a escrita dos configs por usuário precisam
 * de privilégio. NENHUM restart/reload do vsftpd acontece aqui: o
 * banco de usuários virtuais é consultado pelo PAM a cada autenticação
 * e o config por usuário é lido a cada login — CRUD de conta FTP nunca
 * precisa tocar no processo rodando.
 */
class SyncFtpAccountsAction implements AgentAction
{
    public function isAsync(): bool
    {
        return false;
    }

    public function execute(array $payload): array
    {
        $accounts = array_map(fn (array $a) => $this->validateAccount($a), $payload['accounts'] ?? []);

        $this->syncVirtualUsersDb($accounts);
        $this->syncUserConfigs($accounts);

        return ['accounts' => count($accounts)];
    }

    private function validateAccount(array $a): array
    {
        $ftpUsername = (string) ($a['ftp_username'] ?? '');
        $linuxUsername = LinuxUsername::validate($a['linux_username'] ?? '');
        $path = (string) ($a['path'] ?? '');
        $passwordHash = (string) ($a['password_hash'] ?? '');

        if (! preg_match('/^[a-zA-Z0-9._@-]{3,32}$/', $ftpUsername)) {
            throw new InvalidArgumentException("Usuário de FTP inválido: {$ftpUsername}");
        }

        if ($passwordHash === '') {
            throw new InvalidArgumentException('Hash de senha de FTP vazio.');
        }

        $localRoot = FtpChrootPath::resolveExisting($linuxUsername, $path);

        return [
            'ftp_username' => $ftpUsername,
            'linux_username' => $linuxUsername,
            'local_root' => $localRoot,
            'password_hash' => $passwordHash,
        ];
    }

    /** @param list<array{ftp_username: string, password_hash: string}> $accounts */
    private function syncVirtualUsersDb(array $accounts): void
    {
        $dir = config('provisioning.ftp_virtual_users_dir');
        $dbPath = "{$dir}/virtual_users.db";
        $tmpInput = "{$dir}/virtual_users.input.tmp";
        $tmpDb = "{$dir}/virtual_users.db.new";

        // Formato texto do db_load (-T): pares KEY/DATA alternados,
        // uma linha cada, sem linha em branco entre os pares.
        $lines = [];
        foreach ($accounts as $account) {
            $lines[] = $account['ftp_username'];
            $lines[] = $account['password_hash'];
        }

        File::put($tmpInput, implode("\n", $lines).($lines ? "\n" : ''));

        $result = Process::timeout(30)->run(['db_load', '-T', '-t', 'hash', '-f', $tmpInput, $tmpDb]);

        File::delete($tmpInput);

        if ($result->failed()) {
            File::delete($tmpDb);
            throw new RuntimeException('db_load falhou: '.trim($result->errorOutput() ?: $result->output()));
        }

        File::move($tmpDb, $dbPath);
        chmod($dbPath, 0600);
    }

    /** @param list<array{ftp_username: string, linux_username: string, local_root: string}> $accounts */
    private function syncUserConfigs(array $accounts): void
    {
        $dir = config('provisioning.ftp_user_conf_dir');
        $keep = [];

        foreach ($accounts as $account) {
            $path = "{$dir}/{$account['ftp_username']}";
            $contents = implode("\n", [
                "guest_username={$account['linux_username']}",
                "local_root={$account['local_root']}",
                'write_enable=YES',
                '',
            ]);

            File::put($path, $contents);
            chmod($path, 0600);
            $keep[] = $path;
        }

        foreach (File::glob("{$dir}/*") as $existing) {
            if (! in_array($existing, $keep, true)) {
                File::delete($existing);
            }
        }
    }
}

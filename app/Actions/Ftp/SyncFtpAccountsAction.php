<?php

namespace App\Actions\Ftp;

use App\Actions\Contracts\AgentAction;
use App\Services\System\ProcessRunner;
use App\Support\FtpChrootPath;
use App\Support\LinuxUsername;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use RuntimeException;

/**
 * O banco de usuários virtuais (virtual_users.db) é escrito sem sudo —
 * mesmo espírito do CreateVirtualHostAction, o diretório é
 * group-writable pro usuário do Agent, e só é LIDO pelo PAM (sem
 * checagem de dono). Já os configs por usuário (user_config_dir)
 * PRECISAM ser root — o próprio vsftpd recusa (`500 OOPS: config file
 * not owned by correct user`) se não forem, então essa parte passa
 * pelo script sudo `manage-ftp.sh` (ver ProcessRunner::syncFtpUserConfigs).
 * NENHUM restart/reload do vsftpd acontece em nenhuma das duas partes:
 * o banco é consultado pelo PAM a cada autenticação, e o config por
 * usuário é lido a cada login — CRUD de conta FTP nunca precisa tocar
 * no processo rodando.
 */
class SyncFtpAccountsAction implements AgentAction
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
        $lines = array_map(
            fn (array $a) => "{$a['ftp_username']}:{$a['linux_username']}:{$a['local_root']}",
            $accounts
        );

        $bundle = implode("\n", $lines).($lines ? "\n" : '');

        $this->processRunner->syncFtpUserConfigs($bundle);
    }
}

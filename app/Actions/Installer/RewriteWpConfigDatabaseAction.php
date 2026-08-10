<?php

namespace App\Actions\Installer;

use App\Actions\Contracts\AgentAction;
use App\Services\System\ProcessRunner;
use App\Support\DomainName;
use App\Support\FileManagerPath;
use App\Support\LinuxUsername;
use InvalidArgumentException;
use RuntimeException;

/**
 * Clone de staging (só WordPress) — reescreve SÓ as 4 constantes de
 * banco num wp-config.php já existente (a cópia recém-clonada, que
 * ainda aponta pro banco de PRODUÇÃO). Regex direcionada, nunca
 * regenera o arquivo inteiro — preserva salts/prefixo/resto intocados.
 */
class RewriteWpConfigDatabaseAction implements AgentAction
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
        $username = LinuxUsername::validate($payload['username'] ?? '');
        $destDomain = DomainName::validate($payload['dest_domain'] ?? '');
        $dbName = (string) ($payload['db_name'] ?? '');
        $dbUsername = (string) ($payload['db_username'] ?? '');
        $dbPassword = (string) ($payload['db_password'] ?? '');

        if ($dbName === '' || $dbUsername === '' || $dbPassword === '') {
            throw new InvalidArgumentException('Credenciais de banco de dados são obrigatórias.');
        }

        $path = FileManagerPath::resolveExisting($username, 'wp-config.php', $destDomain);
        $content = file_get_contents($path);

        if ($content === false) {
            throw new RuntimeException('Falha ao ler wp-config.php.');
        }

        $content = $this->replaceDefine($content, 'DB_NAME', $dbName);
        $content = $this->replaceDefine($content, 'DB_USER', $dbUsername);
        $content = $this->replaceDefine($content, 'DB_PASSWORD', $dbPassword);
        $content = $this->replaceDefine($content, 'DB_HOST', 'localhost');

        $this->processRunner->manageFile($username, 'write', 'wp-config.php', content: $content, root: $destDomain);

        return ['path' => $path];
    }

    private function replaceDefine(string $content, string $constant, string $value): string
    {
        $escaped = str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
        $pattern = '/define\(\s*[\'"]'.preg_quote($constant, '/').'[\'"]\s*,\s*[\'"](?:[^\'"\\\\]|\\\\.)*[\'"]\s*\);/';

        // preg_replace() (não callback) interpreta backslash na string de
        // SUBSTITUIÇÃO como backreference/escape do PCRE (\\ vira 1 barra
        // só, \1 viraria grupo de captura) — corromperia silenciosamente
        // qualquer senha com barra invertida. preg_replace_callback() usa
        // o retorno literal, sem essa interpretação.
        $count = 0;
        $result = preg_replace_callback(
            $pattern,
            function () use ($constant, $escaped, &$count) {
                $count++;

                return "define( '{$constant}', '{$escaped}' );";
            },
            $content,
            1
        );

        if ($result === null || $count === 0) {
            throw new RuntimeException("Constante {$constant} não encontrada no wp-config.php.");
        }

        return $result;
    }
}

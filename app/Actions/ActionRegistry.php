<?php

namespace App\Actions;

use App\Actions\Backup\CreateBackupAction;
use App\Actions\Contracts\AgentAction;
use App\Actions\Database\CreateMysqlDatabaseAction;
use App\Actions\Database\DeleteMysqlDatabaseAction;
use App\Actions\Demo\DemoAsyncAction;
use App\Actions\Demo\HealthCheckAction;
use App\Actions\Files\CreateDirectoryAction;
use App\Actions\Files\CreateFileAction;
use App\Actions\Files\DeleteFileAction;
use App\Actions\Files\ListDirectoryAction;
use App\Actions\Files\ReadFileAction;
use App\Actions\Files\RenameFileAction;
use App\Actions\Files\WriteFileAction;
use App\Actions\Hosting\ReactivateHostingAccountAction;
use App\Actions\Hosting\SuspendHostingAccountAction;
use App\Actions\Linux\CreateSystemUserAction;
use App\Actions\Linux\DeleteSystemUserAction;
use App\Actions\Php\CreatePhpFpmPoolAction;
use App\Actions\Php\DeletePhpFpmPoolAction;
use App\Actions\Php\SwitchPhpVersionAction;
use App\Actions\Ssl\IssueSslCertificateAction;
use App\Actions\Web\CreateAddonDomainAction;
use App\Actions\Web\CreateVirtualHostAction;
use App\Actions\Web\DeleteAddonDomainAction;
use App\Actions\Web\DeleteVirtualHostAction;
use App\Actions\Web\UpdateVirtualHostPhpVersionAction;
use InvalidArgumentException;

/**
 * Whitelist fechada de ações que o Painel pode disparar. Nenhum comando
 * livre é aceito — só o que está mapeado aqui.
 */
class ActionRegistry
{
    /** @var array<string, class-string<AgentAction>> */
    private const MAP = [
        'demo.health_check' => HealthCheckAction::class,
        'demo.async' => DemoAsyncAction::class,
        'linux.create_user' => CreateSystemUserAction::class,
        'linux.delete_user' => DeleteSystemUserAction::class,
        'web.create_vhost' => CreateVirtualHostAction::class,
        'web.delete_vhost' => DeleteVirtualHostAction::class,
        'php.create_pool' => CreatePhpFpmPoolAction::class,
        'php.delete_pool' => DeletePhpFpmPoolAction::class,
        'database.create_mysql' => CreateMysqlDatabaseAction::class,
        'database.delete_mysql' => DeleteMysqlDatabaseAction::class,
        'hosting.suspend' => SuspendHostingAccountAction::class,
        'hosting.reactivate' => ReactivateHostingAccountAction::class,
        'ssl.issue_certificate' => IssueSslCertificateAction::class,
        'web.create_addon_domain' => CreateAddonDomainAction::class,
        'web.delete_addon_domain' => DeleteAddonDomainAction::class,
        'php.switch_version' => SwitchPhpVersionAction::class,
        'web.update_vhost_php_version' => UpdateVirtualHostPhpVersionAction::class,
        'backup.create' => CreateBackupAction::class,
        'files.list' => ListDirectoryAction::class,
        'files.read' => ReadFileAction::class,
        'files.write' => WriteFileAction::class,
        'files.create_directory' => CreateDirectoryAction::class,
        'files.create_file' => CreateFileAction::class,
        'files.delete' => DeleteFileAction::class,
        'files.rename' => RenameFileAction::class,
    ];

    public static function resolve(string $action): AgentAction
    {
        if (! isset(self::MAP[$action])) {
            throw new InvalidArgumentException("Ação não permitida: {$action}");
        }

        return app(self::MAP[$action]);
    }

    public static function exists(string $action): bool
    {
        return isset(self::MAP[$action]);
    }

    /** @return list<string> */
    public static function allowed(): array
    {
        return array_keys(self::MAP);
    }
}

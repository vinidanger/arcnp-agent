<?php

namespace App\Actions;

use App\Actions\Backup\CreateBackupAction;
use App\Actions\Backup\DeleteBackupAction;
use App\Actions\Contracts\AgentAction;
use App\Actions\Cron\SyncCronJobsAction;
use App\Actions\Database\CreateMysqlDatabaseAction;
use App\Actions\Database\DeleteMysqlDatabaseAction;
use App\Actions\Demo\DemoAsyncAction;
use App\Actions\Demo\HealthCheckAction;
use App\Actions\Disk\DiskUsageAction;
use App\Actions\Disk\SyncDiskQuotaAction;
use App\Actions\Dns\CreateDnsZoneAction;
use App\Actions\Ftp\SyncFtpAccountsAction;
use App\Actions\Dns\DeleteDnsZoneAction;
use App\Actions\Dns\UpdateDnsZoneRecordsAction;
use App\Actions\Files\CompressFilesAction;
use App\Actions\Files\CreateDirectoryAction;
use App\Actions\Files\CreateFileAction;
use App\Actions\Files\DeleteFileAction;
use App\Actions\Files\ExtractArchiveAction;
use App\Actions\Files\ListDirectoryAction;
use App\Actions\Files\ReadFileAction;
use App\Actions\Files\RenameFileAction;
use App\Actions\Files\WriteFileAction;
use App\Actions\Hosting\ReactivateHostingAccountAction;
use App\Actions\Hosting\SuspendHostingAccountAction;
use App\Actions\Installer\InstallWordPressAction;
use App\Actions\Linux\CreateSystemUserAction;
use App\Actions\Linux\DeleteSystemUserAction;
use App\Actions\Mail\DeleteMailDkimAction;
use App\Actions\Mail\SyncMailDkimAction;
use App\Actions\Mail\SyncMailStateAction;
use App\Actions\Mail\TailMailLogAction;
use App\Actions\Php\CreatePhpFpmPoolAction;
use App\Actions\Php\DeletePhpFpmPoolAction;
use App\Actions\Php\ListPhpExtensionsAction;
use App\Actions\Php\SwitchPhpVersionAction;
use App\Actions\Php\TogglePhpExtensionAction;
use App\Actions\Php\UpdatePhpFpmPoolSettingsAction;
use App\Actions\Resources\GetResourceUsageAction;
use App\Actions\Resources\SetResourceLimitsAction;
use App\Actions\Server\CollectServerInfoAction;
use App\Actions\Ssh\SetSshAccessAction;
use App\Actions\Ssh\SetSshPasswordAction;
use App\Actions\Ssh\SyncSshKeysAction;
use App\Actions\Ssl\IssueSslCertificateAction;
use App\Actions\Ssl\RenewAllSslCertificatesAction;
use App\Actions\Web\CreateAddonDomainAction;
use App\Actions\Web\CreateHostedAppAction;
use App\Actions\Web\CreateVirtualHostAction;
use App\Actions\Web\DeleteAddonDomainAction;
use App\Actions\Web\DeleteHostedAppAction;
use App\Actions\Web\DeleteVirtualHostAction;
use App\Actions\Web\HostedAppStatusAction;
use App\Actions\Web\RestartHostedAppAction;
use App\Actions\Web\SyncFolderProtectionsAction;
use App\Actions\Web\SyncHotlinkProtectionAction;
use App\Actions\Web\SyncMimeTypesAction;
use App\Actions\Web\SyncRedirectsAction;
use App\Actions\Web\TailDomainLogAction;
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
        'ssl.renew_all' => RenewAllSslCertificatesAction::class,
        'web.create_addon_domain' => CreateAddonDomainAction::class,
        'web.delete_addon_domain' => DeleteAddonDomainAction::class,
        'php.switch_version' => SwitchPhpVersionAction::class,
        'php.update_pool_settings' => UpdatePhpFpmPoolSettingsAction::class,
        'php.list_extensions' => ListPhpExtensionsAction::class,
        'php.toggle_extension' => TogglePhpExtensionAction::class,
        'web.update_vhost_php_version' => UpdateVirtualHostPhpVersionAction::class,
        'web.sync_folder_protections' => SyncFolderProtectionsAction::class,
        'web.sync_redirects' => SyncRedirectsAction::class,
        'web.sync_hotlink_protection' => SyncHotlinkProtectionAction::class,
        'web.sync_mime_types' => SyncMimeTypesAction::class,
        'web.tail_domain_log' => TailDomainLogAction::class,
        'app.create' => CreateHostedAppAction::class,
        'app.delete' => DeleteHostedAppAction::class,
        'app.restart' => RestartHostedAppAction::class,
        'app.status' => HostedAppStatusAction::class,
        'app.install_wordpress' => InstallWordPressAction::class,
        'backup.create' => CreateBackupAction::class,
        'backup.delete' => DeleteBackupAction::class,
        'files.list' => ListDirectoryAction::class,
        'files.read' => ReadFileAction::class,
        'files.write' => WriteFileAction::class,
        'files.create_directory' => CreateDirectoryAction::class,
        'files.create_file' => CreateFileAction::class,
        'files.delete' => DeleteFileAction::class,
        'files.rename' => RenameFileAction::class,
        'files.compress' => CompressFilesAction::class,
        'files.extract' => ExtractArchiveAction::class,
        'disk.usage' => DiskUsageAction::class,
        'disk.sync_quota' => SyncDiskQuotaAction::class,
        'cron.sync' => SyncCronJobsAction::class,
        'ssh.set_enabled' => SetSshAccessAction::class,
        'ssh.sync_keys' => SyncSshKeysAction::class,
        'ssh.set_password' => SetSshPasswordAction::class,
        'dns.create_zone' => CreateDnsZoneAction::class,
        'dns.update_zone_records' => UpdateDnsZoneRecordsAction::class,
        'dns.delete_zone' => DeleteDnsZoneAction::class,
        'mail.sync_state' => SyncMailStateAction::class,
        'mail.sync_dkim' => SyncMailDkimAction::class,
        'mail.delete_dkim' => DeleteMailDkimAction::class,
        'mail.tail_log' => TailMailLogAction::class,
        'ftp.sync_state' => SyncFtpAccountsAction::class,
        'server.collect_info' => CollectServerInfoAction::class,
        'resources.set_limits' => SetResourceLimitsAction::class,
        'resources.usage' => GetResourceUsageAction::class,
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

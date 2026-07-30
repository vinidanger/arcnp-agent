<?php

namespace App\Actions\Mail;

use App\Actions\Contracts\AgentAction;
use App\Services\System\ProcessRunner;
use App\Support\DomainName;
use App\Support\EmailAddress;
use App\Support\LinuxUsername;
use App\Support\MailPasswordHash;
use App\Support\MailStateBundle;

/**
 * O Painel manda o estado COMPLETO do servidor (todo domínio com
 * e-mail ativo + toda caixa de todas as contas), nunca um diff — mesmo
 * padrão do cron.sync/ssh.sync_keys/dns.create_zone. UID/GID de cada
 * caixa são resolvidos AQUI via `id`, nunca aceitos do Painel — ele
 * não tem como saber o UID real que o useradd atribuiu.
 */
class SyncMailStateAction implements AgentAction
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
        $domains = array_map(fn ($d) => DomainName::validate($d), $payload['domains'] ?? []);

        $mailboxes = array_map(function (array $mailbox) {
            $username = LinuxUsername::validate($mailbox['username'] ?? '');
            $domain = DomainName::validate($mailbox['domain'] ?? '');
            $localPart = EmailAddress::validateLocalPart($mailbox['local_part'] ?? '');
            $passwordHash = MailPasswordHash::validate($mailbox['password_hash'] ?? '');

            return [
                'email' => EmailAddress::build($localPart, $domain),
                'username' => $username,
                'domain' => $domain,
                'local_part' => $localPart,
                'password_hash' => $passwordHash,
                'uid' => $this->processRunner->userId($username),
                'gid' => $this->processRunner->groupId($username),
            ];
        }, $payload['mailboxes'] ?? []);

        $bundle = MailStateBundle::render($domains, $mailboxes);
        $this->processRunner->syncMailState($bundle);

        return ['domains' => count($domains), 'mailboxes' => count($mailboxes)];
    }
}

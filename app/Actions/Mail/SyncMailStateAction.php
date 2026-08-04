<?php

namespace App\Actions\Mail;

use App\Actions\Contracts\AgentAction;
use App\Services\System\ProcessRunner;
use App\Support\DomainName;
use App\Support\EmailAddress;
use App\Support\LinuxUsername;
use App\Support\MailPasswordHash;
use App\Support\MailStateBundle;
use InvalidArgumentException;

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

        $forwarders = array_map(function (array $forwarder) {
            [$localPart, $domain] = array_pad(explode('@', (string) ($forwarder['source'] ?? ''), 2), 2, '');
            $source = EmailAddress::build($localPart, $domain);

            $destination = (string) ($forwarder['destination'] ?? '');

            if (! filter_var($destination, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException("Destino de encaminhamento inválido: {$destination}");
            }

            return ['source' => $source, 'destination' => $destination];
        }, $payload['forwarders'] ?? []);

        $sieveMailboxes = array_map(function (array $entry) {
            $username = LinuxUsername::validate($entry['username'] ?? '');
            $domain = DomainName::validate($entry['domain'] ?? '');
            $localPart = EmailAddress::validateLocalPart($entry['local_part'] ?? '');

            $vacation = null;
            if (($entry['vacation'] ?? null) !== null) {
                $subject = trim((string) ($entry['vacation']['subject'] ?? ''));
                $message = trim((string) ($entry['vacation']['message'] ?? ''));

                if ($subject === '' || $message === '') {
                    throw new InvalidArgumentException('Assunto e mensagem do aviso de férias são obrigatórios.');
                }

                $vacation = ['subject' => $subject, 'message' => $message];
            }

            $filters = array_map(function (array $filter) {
                $field = (string) ($filter['field'] ?? '');
                $value = trim((string) ($filter['value'] ?? ''));
                $action = (string) ($filter['action'] ?? '');
                $folder = $filter['folder'] ?? null;

                if (! in_array($field, ['from', 'subject', 'to'], true)) {
                    throw new InvalidArgumentException("Campo de filtro inválido: {$field}");
                }

                if ($value === '') {
                    throw new InvalidArgumentException('Valor de filtro vazio.');
                }

                if (! in_array($action, ['discard', 'move_to_folder'], true)) {
                    throw new InvalidArgumentException("Ação de filtro inválida: {$action}");
                }

                if ($action === 'move_to_folder' && trim((string) $folder) === '') {
                    throw new InvalidArgumentException('Pasta de destino obrigatória pra ação "mover pra pasta".');
                }

                return [
                    'field' => $field,
                    'value' => $value,
                    'action' => $action,
                    'folder' => $action === 'move_to_folder' ? trim((string) $folder) : null,
                ];
            }, $entry['filters'] ?? []);

            return [
                'username' => $username,
                'domain' => $domain,
                'local_part' => $localPart,
                'uid' => $this->processRunner->userId($username),
                'gid' => $this->processRunner->groupId($username),
                'vacation' => $vacation,
                'filters' => $filters,
            ];
        }, $payload['sieve_mailboxes'] ?? []);

        $bundle = MailStateBundle::render($domains, $mailboxes, $forwarders, $sieveMailboxes);
        $this->processRunner->syncMailState($bundle);

        return [
            'domains' => count($domains),
            'mailboxes' => count($mailboxes),
            'forwarders' => count($forwarders),
            'sieve_mailboxes' => count($sieveMailboxes),
        ];
    }
}

<?php

namespace App\Services\Group\MessageHandler;

use App\Services\Group\GroupInvitationMailer;
use App\Services\Group\GroupRepository;
use App\Services\Group\GroupService;
use App\Services\Group\Message\SendGroupInvitationMessage;
use App\Services\TenantConnectionManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Lot G-1a : la demande d'invitation, traitée hors de la requête du siège (qui reçoit toujours la même réponse). Rien n'est envoyé si le site
 * n'existe pas, s'il est le siège, s'il est déjà dans un groupe, ou si une invitation de ce groupe l'attend déjà : la raison reste au journal
 * du groupe, jamais dans la réponse.
 */
#[AsMessageHandler]
final class SendGroupInvitationHandler
{
    public function __construct(
        private readonly GroupRepository $groups,
        private readonly TenantConnectionManager $tenants,
        private readonly GroupInvitationMailer $mailer,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(SendGroupInvitationMessage $message): void
    {
        $group = $this->groups->find($message->groupId);
        $stmt = $this->tenants->getPdoMaster()->prepare('SELECT code, name, dbname FROM tenants WHERE code = ?');
        $stmt->execute([$message->siteCode]);
        $site = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        $reason = match (true) {
            $group === null || $group['status'] !== 'active' => 'group',
            $site === null => 'unknown',
            $site['code'] === $group['head_tenant_code'] => 'head',
            $this->groups->ofSite((string) $site['code']) !== null => 'member',
            $this->pending((int) $group['id'], (string) $site['code']) => 'pending',
            default => null,
        };
        if ($reason !== null) {
            $group !== null && $this->groups->event((int) $group['id'], 'invitation_skipped', $message->siteCode, ['reason' => $reason], $message->by);

            return;
        }
        $expires = new \DateTimeImmutable('+' . GroupService::INVITATION_DAYS . ' days', new \DateTimeZone('UTC'));
        $id = $this->groups->invite((int) $group['id'], (string) $site['code'], hash('sha256', random_bytes(32)), $message->by, $expires);
        $headName = $this->groups->shops((int) $group['id'])[0]['name'] ?? $group['head_tenant_code'];
        try {
            $sent = $this->mailer->notify((string) $site['dbname'], (string) $site['name'], (string) $group['name'], (string) $headName, GroupService::INVITATION_DAYS, $message->locale);
        } catch (\Throwable $e) {
            $this->logger->error('Groupe : invitation créée, courriel en échec (' . $e::class . ').');
            $sent = 0;
        }
        $this->groups->event((int) $group['id'], 'invited', (string) $site['code'], ['invitation' => $id, 'sent' => $sent], $message->by);
    }

    private function pending(int $groupId, string $code): bool
    {
        foreach ($this->groups->openInvitationsFor($code) as $invitation) {
            if ((int) $invitation['group_id'] === $groupId) {
                return true;
            }
        }

        return false;
    }
}

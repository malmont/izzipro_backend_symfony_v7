<?php

namespace App\Support\Services;

use App\Services\ContentAuditService\ContentAuditRecorder;
use App\Services\TenantEntityManagerProvider;
use App\Support\Entity\SupportAttachment;
use App\Support\Entity\SupportMessage;
use App\Support\Entity\SupportTicket;

/**
 * Conservation des demandes d'assistance (lot 1, décision du propriétaire du 11/11/2026, Loi 25 et RGPD) : un ticket FERMÉ depuis plus de 3 ans
 * est anonymisé (nom, courriel, corps des messages remplacés, pièces jointes supprimées ; référence, dates, état, canal, priorité et délais
 * gardés pour les statistiques). Un ticket rouvert repart de zéro à sa nouvelle fermeture. Simulation par défaut ; rejouable sans effet.
 */
class SupportRetentionService
{
    public const PLACEHOLDER = '[anonymisé]';

    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly SupportAttachmentStorage $storage, private readonly ContentAuditRecorder $audit)
    {
    }

    /** @return list<string> références anonymisées (ou à anonymiser en simulation) */
    public function run(bool $apply, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $limit = $now->modify('-' . SupportSettingsService::RETENTION_YEARS . ' years')->format('Y-m-d H:i:s');
        $em = $this->emProvider->getEntityManager();
        $ids = $em->getConnection()->fetchFirstColumn("SELECT id FROM support_ticket WHERE status = 'closed' AND closed_at IS NOT NULL AND closed_at < ? AND anonymized_at IS NULL ORDER BY id", [$limit]);
        $done = [];
        foreach ($ids as $id) {
            $ticket = $em->find(SupportTicket::class, (int) $id);
            $done[] = $ticket->getReference();
            if (!$apply) {
                continue;
            }
            foreach ($em->getRepository(SupportMessage::class)->findBy(['ticket' => $ticket]) as $m) {
                foreach ($em->getRepository(SupportAttachment::class)->findBy(['message' => $m]) as $a) {
                    $this->storage->remove((int) $ticket->getId(), $a->getStoredName());
                    $em->remove($a);
                }
                $m->setBody(self::PLACEHOLDER)->setAuthorName($m->getAuthorType() === 'customer' ? self::PLACEHOLDER : $m->getAuthorName());
            }
            $ticket->setCustomerName(null)->setCustomerEmail(null)->setCustomerUserId(null)->setSubject(self::PLACEHOLDER)->setAnonymizedAt($now);
            $em->flush();
            $this->audit->record('support_ticket', (int) $ticket->getId(), 'anonymize', null, ['reference' => $ticket->getReference()], ['anonymized']);
        }

        return $done;
    }
}

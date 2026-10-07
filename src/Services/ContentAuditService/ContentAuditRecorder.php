<?php

namespace App\Services\ContentAuditService;

use App\Entity\ContentAuditLog;
use App\Repository\ContentAuditLogRepository;
use App\Services\TenantConnectionProvider;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Inscrit une écriture de l'éditeur des landing pages dans le journal du site (content_audit_log) : utilisateur,
 * site, ressource, action, champs modifiés, état avant et après (JSON). Les entrées de plus de RETENTION_DAYS jours
 * sont supprimées au fil des écritures.
 */
final class ContentAuditRecorder
{
    public const RETENTION_DAYS = 180;
    public const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly TenantConnectionProvider $tenantProvider,
        private readonly Security $security
    ) {
    }

    /**
     * @param mixed $before état avant (encodé en JSON ; une chaîne est prise pour du JSON déjà encodé)
     * @param mixed $after état après (idem)
     * @param list<string> $fields champs modifiés
     */
    public function record(string $resource, int|string|null $resourceId, string $action, mixed $before, mixed $after, array $fields = [], ?string $locale = null, ?int $restoredFrom = null): ContentAuditLog
    {
        $entry = (new ContentAuditLog())
            ->setUser($this->security->getUser()?->getUserIdentifier())
            ->setTenant((string) ($this->tenantProvider->getTenantCode() ?? ''))
            ->setResource($resource)
            ->setResourceId($resourceId === null ? null : (string) $resourceId)
            ->setAction($action)
            ->setLocale($locale)
            ->setFields($fields)
            ->setBefore(self::encode($before))
            ->setAfter(self::encode($after))
            ->setRestoredFrom($restoredFrom);
        $em = $this->emProvider->getEntityManager();
        $em->persist($entry);
        $em->flush();
        $em->getRepository(ContentAuditLog::class)->deleteOlderThan(new \DateTimeImmutable(sprintf('-%d days', self::RETENTION_DAYS)));

        return $entry;
    }

    public function repository(): ContentAuditLogRepository
    {
        return $this->emProvider->getEntityManager()->getRepository(ContentAuditLog::class);
    }

    private static function encode(mixed $state): ?string
    {
        return match (true) {
            $state === null => null,
            is_string($state) => $state,
            default => json_encode($state, self::JSON_FLAGS | JSON_THROW_ON_ERROR),
        };
    }
}

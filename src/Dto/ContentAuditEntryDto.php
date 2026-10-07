<?php

namespace App\Dto;

use App\Entity\ContentAuditLog;
use App\Services\ContentAuditService\ContentAuditRestorer;

/** Entrée du journal des écritures (GET /api/landingpage-audit) ; before et after seulement dans le détail */
final class ContentAuditEntryDto
{
    public function __construct(
        public readonly int $id,
        public readonly string $createdAt,
        public readonly ?string $user,
        public readonly string $resource,
        public readonly ?string $resourceId,
        public readonly string $action,
        public readonly ?string $locale,
        /** @var list<string> */
        public readonly array $fields,
        public readonly bool $restorable,
        public readonly ?int $restoredFrom,
        public readonly mixed $before = null,
        public readonly mixed $after = null
    ) {
    }

    public static function fromEntity(ContentAuditLog $entry, bool $withStates = false): self
    {
        return new self(
            (int) $entry->getId(),
            $entry->getCreatedAt()->format(\DateTimeInterface::ATOM),
            $entry->getUser(),
            $entry->getResource(),
            $entry->getResourceId(),
            $entry->getAction(),
            $entry->getLocale(),
            $entry->getFields(),
            ContentAuditRestorer::restorable($entry),
            $entry->getRestoredFrom(),
            $withStates && $entry->getBefore() !== null ? json_decode($entry->getBefore()) : null,
            $withStates && $entry->getAfter() !== null ? json_decode($entry->getAfter()) : null
        );
    }
}

<?php

namespace App\Entity;

use App\Repository\ContentAuditLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Journal des écritures faites depuis l'éditeur des landing pages : qui, quand, sur quel site, quelle ressource, et
 * l'état avant / après des champs modifiés (JSON). Sert à relire l'historique et à revenir à l'état d'avant.
 */
#[ORM\Entity(repositoryClass: ContentAuditLogRepository::class)]
#[ORM\Table(name: 'content_audit_log')]
#[ORM\Index(columns: ['created_at'], name: 'idx_content_audit_created_at')]
#[ORM\Index(columns: ['resource', 'resource_id'], name: 'idx_content_audit_resource')]
class ContentAuditLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'user_identifier', length: 180, nullable: true)]
    private ?string $user = null;

    #[ORM\Column(length: 64)]
    private string $tenant = '';

    #[ORM\Column(length: 40)]
    private string $resource = '';

    #[ORM\Column(name: 'resource_id', length: 40, nullable: true)]
    private ?string $resourceId = null;

    #[ORM\Column(length: 20)]
    private string $action = '';

    #[ORM\Column(length: 5, nullable: true)]
    private ?string $locale = null;

    /** Champs modifiés, séparés par des virgules */
    #[ORM\Column(length: 500)]
    private string $fields = '';

    #[ORM\Column(name: 'before_state', type: Types::TEXT, nullable: true)]
    private ?string $before = null;

    #[ORM\Column(name: 'after_state', type: Types::TEXT, nullable: true)]
    private ?string $after = null;

    /** Entrée annulée par celle-ci (action « restore ») */
    #[ORM\Column(name: 'restored_from', nullable: true)]
    private ?int $restoredFrom = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUser(): ?string { return $this->user; }
    public function setUser(?string $user): static { $this->user = $user; return $this; }
    public function getTenant(): string { return $this->tenant; }
    public function setTenant(string $tenant): static { $this->tenant = $tenant; return $this; }
    public function getResource(): string { return $this->resource; }
    public function setResource(string $resource): static { $this->resource = $resource; return $this; }
    public function getResourceId(): ?string { return $this->resourceId; }
    public function setResourceId(?string $resourceId): static { $this->resourceId = $resourceId; return $this; }
    public function getAction(): string { return $this->action; }
    public function setAction(string $action): static { $this->action = $action; return $this; }
    public function getLocale(): ?string { return $this->locale; }
    public function setLocale(?string $locale): static { $this->locale = $locale; return $this; }
    /** @return list<string> */
    public function getFields(): array { return $this->fields === '' ? [] : explode(',', $this->fields); }
    /** @param list<string> $fields */
    public function setFields(array $fields): static { $this->fields = mb_substr(implode(',', $fields), 0, 500); return $this; }
    public function getBefore(): ?string { return $this->before; }
    public function setBefore(?string $before): static { $this->before = $before; return $this; }
    public function getAfter(): ?string { return $this->after; }
    public function setAfter(?string $after): static { $this->after = $after; return $this; }
    public function getRestoredFrom(): ?int { return $this->restoredFrom; }
    public function setRestoredFrom(?int $restoredFrom): static { $this->restoredFrom = $restoredFrom; return $this; }
}

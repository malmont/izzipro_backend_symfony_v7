<?php

namespace App\Gestion\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Fil de conversation de la gestion (lot IA-FACT-1, 13/11/2026, `docs/conversation-gestion.md`) : mène une facture fournisseur de la
 * pièce jointe à la commande. Un seul fil ouvert par document. Les réponses du gérant aux questions du serveur sont dans `answers`
 * (identifiant de question => valeur) ; l'état, les questions et l'aperçu se recalculent à chaque lecture. Jamais lu en cache applicatif
 * (`CacheTags::NO_CACHE_READ`).
 */
#[ORM\Entity]
#[ORM\Table(name: 'gestion_conversation')]
class GestionConversation
{
    public const KIND_INVOICE = 'invoice';
    public const STATUS_OPEN = 'open';
    public const STATUS_ABANDONED = 'abandoned';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: GestionDocument::class)]
    #[ORM\JoinColumn(name: 'document_id', nullable: true, onDelete: 'CASCADE')]
    private ?GestionDocument $document = null;

    #[ORM\Column(length: 20, options: ['default' => self::KIND_INVOICE])]
    private string $kind = self::KIND_INVOICE;

    #[ORM\Column(length: 20, options: ['default' => self::STATUS_OPEN])]
    private string $status = self::STATUS_OPEN;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $answers = null;

    #[ORM\Column(length: 5, options: ['default' => 'fr'])]
    private string $locale = 'fr';

    #[ORM\Column(name: 'created_by', length: 255, nullable: true)]
    private ?string $createdBy = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private ?\DateTimeImmutable $updatedAt = null;

    public function getId(): ?int { return $this->id; }
    public function getDocument(): ?GestionDocument { return $this->document; }
    public function setDocument(?GestionDocument $document): static { $this->document = $document; return $this; }
    public function getKind(): string { return $this->kind; }
    /** `invoice`, ou la clé d'une action du catalogue (20 caractères au plus) */
    public function setKind(string $kind): static { $this->kind = $kind; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }
    /** @return array<string, mixed> */
    public function getAnswers(): array { return $this->answers ?? []; }
    /** @param array<string, mixed> $answers */
    public function setAnswers(array $answers): static { $this->answers = $answers === [] ? null : $answers; return $this; }
    public function getLocale(): string { return $this->locale; }
    public function setLocale(string $locale): static { $this->locale = $locale; return $this; }
    public function getCreatedBy(): ?string { return $this->createdBy; }
    public function setCreatedBy(?string $by): static { $this->createdBy = $by; return $this; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $at): static { $this->createdAt = $at; return $this; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(\DateTimeImmutable $at): static { $this->updatedAt = $at; return $this; }
}

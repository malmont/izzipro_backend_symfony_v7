<?php

namespace App\Entity;

use App\Repository\GestionDocumentRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Document lu par IA pour l'application de gestion (lot L1, 10/11/2026, `docs/lecture-documents-gestion.md`) : facture fournisseur,
 * note de frais ou facture de transport. Fichiers privés (`var/storage/private_media/gestion-documents/<site>/<id>/`), extraction
 * JSON, brouillon de vérification, données confirmées ; justificatif de la commande, de la note ou du frais de port créé.
 * Jamais lu en cache applicatif (`CacheTags::NO_CACHE_READ`).
 */
#[ORM\Entity(repositoryClass: GestionDocumentRepository::class)]
#[ORM\Table(name: 'gestion_document')]
class GestionDocument
{
    public const TYPE_FACTURE = 'facture';
    public const TYPE_NOTE = 'note';
    public const TYPE_TRANSPORT = 'transport';
    public const TYPES = [self::TYPE_FACTURE, self::TYPE_NOTE, self::TYPE_TRANSPORT];

    public const STATUS_READING = 'reading';
    public const STATUS_EXTRACTED = 'extracted';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_EXPIRED = 'expired';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 16)]
    private string $type = self::TYPE_FACTURE;

    #[ORM\Column(length: 16)]
    private string $status = self::STATUS_READING;

    #[ORM\Column(length: 5, options: ['default' => 'fr'])]
    private string $locale = 'fr';

    /** @var array{pages: list<array{n: int, name: string, mime: string, size: int, file: string, pages?: int}>, linePhotos?: array<string, array{name: string, mime: string, size: int, file: string}>} */
    #[ORM\Column(type: 'json')]
    private array $files = ['pages' => []];

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $extraction = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $draft = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $confirmed = null;

    #[ORM\Column(name: 'error_code', length: 40, nullable: true)]
    private ?string $errorCode = null;

    #[ORM\Column(name: 'ai_usage_id', nullable: true)]
    private ?int $aiUsageId = null;

    #[ORM\Column(name: 'commande_id', nullable: true)]
    private ?int $commandeId = null;

    #[ORM\Column(name: 'note_de_frais_id', nullable: true)]
    private ?int $noteDeFraisId = null;

    #[ORM\Column(name: 'frais_de_port_id', nullable: true)]
    private ?int $fraisDePortId = null;

    #[ORM\Column(name: 'created_by', length: 255, nullable: true)]
    private ?string $createdBy = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(name: 'read_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $readAt = null;

    #[ORM\Column(name: 'draft_updated_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $draftUpdatedAt = null;

    #[ORM\Column(name: 'confirmed_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $confirmedAt = null;

    #[ORM\Column(name: 'expires_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    public function getId(): ?int { return $this->id; }
    public function getType(): string { return $this->type; }
    public function setType(string $type): static { $this->type = $type; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }
    public function getLocale(): string { return $this->locale; }
    public function setLocale(string $locale): static { $this->locale = $locale; return $this; }
    public function getFiles(): array { return $this->files; }
    public function setFiles(array $files): static { $this->files = $files; return $this; }
    public function getExtraction(): ?array { return $this->extraction; }
    public function setExtraction(?array $extraction): static { $this->extraction = $extraction; return $this; }
    public function getDraft(): ?array { return $this->draft; }
    public function setDraft(?array $draft): static { $this->draft = $draft; return $this; }
    public function getConfirmed(): ?array { return $this->confirmed; }
    public function setConfirmed(?array $confirmed): static { $this->confirmed = $confirmed; return $this; }
    public function getErrorCode(): ?string { return $this->errorCode; }
    public function setErrorCode(?string $code): static { $this->errorCode = $code; return $this; }
    public function getAiUsageId(): ?int { return $this->aiUsageId; }
    public function setAiUsageId(?int $id): static { $this->aiUsageId = $id; return $this; }
    public function getCommandeId(): ?int { return $this->commandeId; }
    public function setCommandeId(?int $id): static { $this->commandeId = $id; return $this; }
    public function getNoteDeFraisId(): ?int { return $this->noteDeFraisId; }
    public function setNoteDeFraisId(?int $id): static { $this->noteDeFraisId = $id; return $this; }
    public function getFraisDePortId(): ?int { return $this->fraisDePortId; }
    public function setFraisDePortId(?int $id): static { $this->fraisDePortId = $id; return $this; }
    public function getCreatedBy(): ?string { return $this->createdBy; }
    public function setCreatedBy(?string $by): static { $this->createdBy = $by; return $this; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $at): static { $this->createdAt = $at; return $this; }
    public function getReadAt(): ?\DateTimeImmutable { return $this->readAt; }
    public function setReadAt(?\DateTimeImmutable $at): static { $this->readAt = $at; return $this; }
    public function getDraftUpdatedAt(): ?\DateTimeImmutable { return $this->draftUpdatedAt; }
    public function setDraftUpdatedAt(?\DateTimeImmutable $at): static { $this->draftUpdatedAt = $at; return $this; }
    public function getConfirmedAt(): ?\DateTimeImmutable { return $this->confirmedAt; }
    public function setConfirmedAt(?\DateTimeImmutable $at): static { $this->confirmedAt = $at; return $this; }
    public function getExpiresAt(): ?\DateTimeImmutable { return $this->expiresAt; }
    public function setExpiresAt(?\DateTimeImmutable $at): static { $this->expiresAt = $at; return $this; }

    public function isConfirmed(): bool { return $this->status === self::STATUS_CONFIRMED; }

    /** Numéro et date de facture lus ou confirmés (pour les justificatifs des commandes, notes et frais) */
    public function invoiceSummary(): array
    {
        $source = $this->confirmed ?? $this->extraction ?? [];
        $invoice = is_array($source['invoice'] ?? null) ? $source['invoice'] : [];

        return ['id' => $this->id, 'type' => $this->type, 'invoiceNumber' => $invoice['number'] ?? null, 'invoiceDate' => $invoice['date'] ?? ($source['date'] ?? null)];
    }
}

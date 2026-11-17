<?php

namespace App\LossPrevention\Entity;

use App\LossPrevention\Repository\LossEvidenceRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Preuve d'un constat (lot L1-a, `docs/prevention-pertes.md` § 6) : copie figée des données utiles (`snapshot`, JSON canonique, sans nom
 * ni courriel : des identifiants) ET renvoi à la source (`refType`, `refId` ; pour une entrée du journal de caisse, sa séquence et son
 * empreinte). `fingerprint` = SHA-256 de la copie. INALTÉRABLE : un déclencheur de la base refuse modification, suppression et vidage
 * (migration `Version20270101100000`). Une preuve par (constat, source) : rejouer l'analyse n'en ajoute pas.
 */
#[ORM\Entity(repositoryClass: LossEvidenceRepository::class)]
#[ORM\Table(name: 'loss_evidence')]
#[ORM\UniqueConstraint(name: 'uniq_loss_evidence_ref', columns: ['finding_id', 'ref_type', 'ref_id'])]
class LossEvidence
{
    public const POS_AUDIT = 'pos_audit';
    public const ORDER = 'order';
    public const CREDIT_NOTE = 'credit_note';
    public const Z_REPORT = 'z_report';
    public const DRAWER = 'drawer';
    public const RETURN = 'return';
    public const DISCOUNT = 'discount';
    public const PRICE_CHANGE = 'price_change';
    public const APPROVAL = 'approval';
    /** Lot L3-b2b : les nombres d'articles suivis vendus sur un poste et une journée, à une clôture (aucune vente, aucun compte) */
    public const SALE_UNITS = 'sale_units';
    /** Lot L4-b : une pièce lue à la sortie (l'antenne, l'heure, la dernière zone d'avant ; aucune personne) */
    public const EXIT_READ = 'exit_read';
    /** Lot L4-c : un mouvement de zone d'une pièce (arrivée ou départ), pour les règles du dépôt et des heures d'ouverture */
    public const ZONE_MOVE = 'zone_move';
    /** Lot L4-d : une pièce non lue à deux comptages de suite (son numéro, son article, le comptage) ; et la lecture qui l'a retrouvée */
    public const COUNT_ABSENCE = 'count_absence';
    public const COUNT_FOUND = 'count_found';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'finding_id')]
    private int $findingId;

    #[ORM\Column(length: 20)]
    private string $kind;

    #[ORM\Column(name: 'occurred_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(name: 'ref_type', length: 20)]
    private string $refType;

    #[ORM\Column(name: 'ref_id')]
    private int $refId;

    #[ORM\Column(name: 'audit_sequence', nullable: true)]
    private ?int $auditSequence = null;

    #[ORM\Column(name: 'audit_hash', length: 64, nullable: true)]
    private ?string $auditHash = null;

    #[ORM\Column(type: 'text')]
    private string $snapshot;

    #[ORM\Column(length: 64)]
    private string $fingerprint;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function getId(): ?int { return $this->id; }
    public function getFindingId(): int { return $this->findingId; }
    public function getKind(): string { return $this->kind; }
    public function getOccurredAt(): \DateTimeImmutable { return $this->occurredAt; }
    public function getRefType(): string { return $this->refType; }
    public function getRefId(): int { return $this->refId; }
    public function getAuditSequence(): ?int { return $this->auditSequence; }
    public function getAuditHash(): ?string { return $this->auditHash; }
    public function getSnapshot(): string { return $this->snapshot; }
    public function getFingerprint(): string { return $this->fingerprint; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}

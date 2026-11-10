<?php

namespace App\Entity;

use App\Repository\CommandeLineRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ligne d'achat (lot L0 de la gestion, 10/11/2026, `docs/lignes-achat-gestion.md`) : un article (ou une variante) acheté dans une
 * commande fournisseur, avec sa quantité commandée, sa quantité reçue, son coût unitaire en cents dans la devise de la commande
 * (NULL = coût inconnu, jamais le prix de vente) et sa date de saisie (`attachedAt`, coupure des résultats de période). Un article peut
 * avoir des lignes dans plusieurs commandes (réassort). Écrite seulement par `CommandeLineService` ; les champs `commande*` du produit
 * en sont des raccourcis dérivés de sa dernière ligne.
 */
#[ORM\Entity(repositoryClass: CommandeLineRepository::class)]
#[ORM\Table(name: 'commande_line')]
class CommandeLine
{
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_REPRISE = 'reprise';
    public const SOURCE_DOCUMENT = 'document';
    public const SOURCE_MIGRATION = 'migration';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Commande::class)]
    #[ORM\JoinColumn(name: 'commande_id', nullable: false, onDelete: 'CASCADE')]
    private ?Commande $commande = null;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(name: 'product_id', nullable: false, onDelete: 'CASCADE')]
    private ?Product $product = null;

    #[ORM\ManyToOne(targetEntity: ProductVariant::class)]
    #[ORM\JoinColumn(name: 'product_variant_id', nullable: true, onDelete: 'SET NULL')]
    private ?ProductVariant $variant = null;

    #[ORM\Column(name: 'quantity_ordered', options: ['default' => 0])]
    private int $quantityOrdered = 0;

    #[ORM\Column(name: 'quantity_received', options: ['default' => 0])]
    private int $quantityReceived = 0;

    #[ORM\Column(name: 'unit_cost_cents', nullable: true)]
    private ?int $unitCostCents = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $estimated = false;

    #[ORM\Column(name: 'attached_at', type: 'datetime_immutable')]
    private ?\DateTimeImmutable $attachedAt = null;

    #[ORM\Column(length: 16, options: ['default' => 'manual'])]
    private string $source = self::SOURCE_MANUAL;

    #[ORM\Column(name: 'created_by', length: 255, nullable: true)]
    private ?string $createdBy = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private ?\DateTimeImmutable $updatedAt = null;

    public function getId(): ?int { return $this->id; }
    public function getCommande(): ?Commande { return $this->commande; }
    public function setCommande(Commande $commande): static { $this->commande = $commande; return $this; }
    public function getProduct(): ?Product { return $this->product; }
    public function setProduct(Product $product): static { $this->product = $product; return $this; }
    public function getVariant(): ?ProductVariant { return $this->variant; }
    public function setVariant(?ProductVariant $variant): static { $this->variant = $variant; return $this; }
    public function getQuantityOrdered(): int { return $this->quantityOrdered; }
    public function setQuantityOrdered(int $quantity): static { $this->quantityOrdered = $quantity; return $this; }
    public function getQuantityReceived(): int { return $this->quantityReceived; }
    public function setQuantityReceived(int $quantity): static { $this->quantityReceived = $quantity; return $this; }
    public function getUnitCostCents(): ?int { return $this->unitCostCents; }
    public function setUnitCostCents(?int $cents): static { $this->unitCostCents = $cents; return $this; }
    public function isEstimated(): bool { return $this->estimated; }
    public function setEstimated(bool $estimated): static { $this->estimated = $estimated; return $this; }
    public function getAttachedAt(): ?\DateTimeImmutable { return $this->attachedAt; }
    public function setAttachedAt(\DateTimeImmutable $at): static { $this->attachedAt = $at; return $this; }
    public function getSource(): string { return $this->source; }
    public function setSource(string $source): static { $this->source = $source; return $this; }
    public function getCreatedBy(): ?string { return $this->createdBy; }
    public function setCreatedBy(?string $by): static { $this->createdBy = $by; return $this; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $at): static { $this->createdAt = $at; return $this; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(\DateTimeImmutable $at): static { $this->updatedAt = $at; return $this; }
}

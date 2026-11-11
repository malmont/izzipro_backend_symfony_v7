<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Promotion d'une variante (lot VAR, 11/11/2026, `docs/promotions-gestion.md` § 8) : prix promotionnel `price` (cents) figé à l'application
 * (`base_price` × (1 − `percent` / 100), arrondi vers le bas comme P5), valable sur [starts_at, ends_at[ (bornes UTC des jours du magasin ;
 * invariant : ends_at = minuit du magasin le lendemain de end_date). Lue par `VariantPriceResolver` dans le calcul commun des prix de vente
 * (boutique, caisse, commande, favoris, pack hors ligne v4, fiche) seulement quand l'interrupteur du site est allumé ; la plus précise gagne :
 * elle remplace la promotion de l'article, sans cumul. Écrite par `GestionVariantPromotionService` ; une écriture vide le cache de son article.
 */
#[ORM\Entity]
#[ORM\Table(name: 'variant_promotion')]
#[ORM\Index(name: 'idx_variant_promotion_variant', columns: ['product_variant_id', 'ends_at'])]
#[ORM\Index(name: 'idx_variant_promotion_batch', columns: ['batch_id'])]
class VariantPromotion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ProductVariant::class, inversedBy: 'variantPromotions')]
    #[ORM\JoinColumn(name: 'product_variant_id', nullable: false)]
    private ?ProductVariant $productVariant = null;

    #[ORM\Column(name: 'batch_id', nullable: true)]
    private ?int $batchId = null;

    #[ORM\Column(type: 'decimal', precision: 5, scale: 2)]
    private string $percent = '0';

    /** Prix promotionnel (cents), figé à l'application */
    #[ORM\Column]
    private int $price = 0;

    /** Prix de départ à l'application (cents) : prix propre de la variante, sinon prix normal de l'article */
    #[ORM\Column(name: 'base_price')]
    private int $basePrice = 0;

    /** Jours du magasin de la fenêtre, inclus */
    #[ORM\Column(name: 'start_date', type: 'date_immutable')]
    private ?\DateTimeImmutable $startDate = null;

    #[ORM\Column(name: 'end_date', type: 'date_immutable')]
    private ?\DateTimeImmutable $endDate = null;

    #[ORM\Column(name: 'starts_at', type: 'datetime_immutable')]
    private ?\DateTimeImmutable $startsAt = null;

    #[ORM\Column(name: 'ends_at', type: 'datetime_immutable')]
    private ?\DateTimeImmutable $endsAt = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(name: 'ended_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $endedAt = null;

    public function getId(): ?int { return $this->id; }
    public function getProductVariant(): ?ProductVariant { return $this->productVariant; }
    public function setProductVariant(ProductVariant $v): static { $this->productVariant = $v; return $this; }
    /** Article de la variante : étiquette de cache (`CacheTags`) */
    public function getProduct(): ?Product { return $this->productVariant?->getProduct(); }
    public function getBatchId(): ?int { return $this->batchId; }
    public function setBatchId(?int $id): static { $this->batchId = $id; return $this; }
    public function getPercent(): float { return (float) $this->percent; }
    public function setPercent(float $p): static { $this->percent = number_format($p, 2, '.', ''); return $this; }
    public function getPrice(): int { return $this->price; }
    public function setPrice(int $c): static { $this->price = $c; return $this; }
    public function getBasePrice(): int { return $this->basePrice; }
    public function setBasePrice(int $c): static { $this->basePrice = $c; return $this; }
    public function getStartDate(): ?\DateTimeImmutable { return $this->startDate; }
    public function getEndDate(): ?\DateTimeImmutable { return $this->endDate; }
    public function setDays(\DateTimeImmutable $start, \DateTimeImmutable $end): static { $this->startDate = $start; $this->endDate = $end; return $this; }
    public function getStartsAt(): ?\DateTimeImmutable { return $this->startsAt; }
    public function setStartsAt(\DateTimeImmutable $d): static { $this->startsAt = $d; return $this; }
    public function getEndsAt(): ?\DateTimeImmutable { return $this->endsAt; }
    public function setEndsAt(\DateTimeImmutable $d): static { $this->endsAt = $d; return $this; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $d): static { $this->createdAt = $d; return $this; }
    public function getEndedAt(): ?\DateTimeImmutable { return $this->endedAt; }
    public function setEndedAt(?\DateTimeImmutable $d): static { $this->endedAt = $d; return $this; }

    /** Instants UTC de la fenêtre (les colonnes sont sans fuseau, en heure UTC) */
    public function window(): array
    {
        $utc = new \DateTimeZone('UTC');

        return [new \DateTimeImmutable($this->startsAt->format('Y-m-d H:i:s'), $utc), new \DateTimeImmutable($this->endsAt->format('Y-m-d H:i:s'), $utc)];
    }
}

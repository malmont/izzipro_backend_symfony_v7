<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Promotion de location d'un article (lot LOC3, 11/11/2026, `docs/locations-gestion.md` § 3) : `percent` de remise sur le tarif de la grille ×
 * unités (jamais sur le supplément passagers ni la caution), pour une location dont le **jour de début** (fuseau du magasin) tombe dans
 * [starts_at, ends_at[ (bornes UTC des jours du magasin) et sur un jour coché (`weekdays`, 1 lundi … 7 dimanche). Lue par
 * `RentalPromotionService` dans le calcul commun des devis (boutique et caisse) ; écrite par `GestionRentalPromotionService`.
 * Une écriture vide l'étiquette de cache de son article (`CacheTags`, `getProduct`).
 */
#[ORM\Entity]
#[ORM\Table(name: 'rental_promotion')]
#[ORM\Index(name: 'idx_rental_promotion_product', columns: ['product_id', 'ends_at'])]
#[ORM\Index(name: 'idx_rental_promotion_batch', columns: ['batch_id'])]
class RentalPromotion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'rentalPromotions')]
    #[ORM\JoinColumn(name: 'product_id', nullable: false)]
    private ?Product $product = null;

    #[ORM\Column(name: 'batch_id', nullable: true)]
    private ?int $batchId = null;

    #[ORM\Column(type: 'smallint')]
    private int $percent = 0;

    /** Jours du magasin de la fenêtre, inclus (fiche de l'article) */
    #[ORM\Column(name: 'start_date', type: 'date_immutable')]
    private ?\DateTimeImmutable $startDate = null;

    #[ORM\Column(name: 'end_date', type: 'date_immutable')]
    private ?\DateTimeImmutable $endDate = null;

    #[ORM\Column(name: 'starts_at', type: 'datetime_immutable')]
    private ?\DateTimeImmutable $startsAt = null;

    #[ORM\Column(name: 'ends_at', type: 'datetime_immutable')]
    private ?\DateTimeImmutable $endsAt = null;

    /** @var list<int> */
    #[ORM\Column(type: 'json')]
    private array $weekdays = [];

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(name: 'ended_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $endedAt = null;

    public function getId(): ?int { return $this->id; }
    public function getProduct(): ?Product { return $this->product; }
    public function setProduct(Product $p): static { $this->product = $p; return $this; }
    public function getBatchId(): ?int { return $this->batchId; }
    public function setBatchId(?int $id): static { $this->batchId = $id; return $this; }
    public function getPercent(): int { return $this->percent; }
    public function setPercent(int $p): static { $this->percent = $p; return $this; }
    public function getStartDate(): ?\DateTimeImmutable { return $this->startDate; }
    public function getEndDate(): ?\DateTimeImmutable { return $this->endDate; }
    public function setDays(\DateTimeImmutable $start, \DateTimeImmutable $end): static { $this->startDate = $start; $this->endDate = $end; return $this; }
    public function getStartsAt(): ?\DateTimeImmutable { return $this->startsAt; }
    public function setStartsAt(\DateTimeImmutable $d): static { $this->startsAt = $d; return $this; }
    public function getEndsAt(): ?\DateTimeImmutable { return $this->endsAt; }
    public function setEndsAt(\DateTimeImmutable $d): static { $this->endsAt = $d; return $this; }
    /** @return list<int> */
    public function getWeekdays(): array { return array_values(array_map('intval', $this->weekdays)); }
    public function setWeekdays(array $w): static { $this->weekdays = array_values($w); return $this; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $d): static { $this->createdAt = $d; return $this; }
    public function getEndedAt(): ?\DateTimeImmutable { return $this->endedAt; }
    public function setEndedAt(?\DateTimeImmutable $d): static { $this->endedAt = $d; return $this; }
}

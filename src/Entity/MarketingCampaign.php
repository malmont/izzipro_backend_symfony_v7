<?php

namespace App\Entity;

use App\Repository\MarketingCampaignRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Campagne de publicité (lot A1 de la gestion, 10/11/2026, `docs/publicite-gestion.md`) : canal, dates, montant dans la devise de la régie
 * (`currency`, NULL = devise du site) avec son taux vers la devise du site, articles et commandes visés (facultatifs), justificatif facultatif
 * (document lu par IA). Classée dans la période de sa date de début ; comptée dans les dépenses de cette période.
 */
#[ORM\Entity(repositoryClass: MarketingCampaignRepository::class)]
#[ORM\Table(name: 'marketing_campaign')]
class MarketingCampaign
{
    public const CHANNELS = ['facebook', 'instagram', 'google', 'tiktok', 'email', 'print', 'radio', 'flyer', 'influence', 'event', 'other'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $name = '';

    #[ORM\Column(length: 20)]
    private string $channel = 'other';

    #[ORM\Column(name: 'start_date', type: 'date_immutable')]
    private ?\DateTimeImmutable $startDate = null;

    #[ORM\Column(name: 'end_date', type: 'date_immutable')]
    private ?\DateTimeImmutable $endDate = null;

    #[ORM\Column(name: 'amount_cents')]
    private int $amountCents = 0;

    #[ORM\Column(length: 3, nullable: true)]
    private ?string $currency = null;

    #[ORM\Column(name: 'exchange_rate', type: 'decimal', precision: 12, scale: 6, nullable: true)]
    private ?string $exchangeRate = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\ManyToOne(targetEntity: GestionDocument::class)]
    #[ORM\JoinColumn(name: 'document_id', nullable: true, onDelete: 'SET NULL')]
    private ?GestionDocument $document = null;

    /** @var Collection<int, Product> */
    #[ORM\ManyToMany(targetEntity: Product::class)]
    #[ORM\JoinTable(name: 'marketing_campaign_product', joinColumns: [new ORM\JoinColumn(name: 'campaign_id', referencedColumnName: 'id', onDelete: 'CASCADE')], inverseJoinColumns: [new ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', onDelete: 'CASCADE')])]
    private Collection $products;

    /** @var Collection<int, Commande> */
    #[ORM\ManyToMany(targetEntity: Commande::class)]
    #[ORM\JoinTable(name: 'marketing_campaign_commande', joinColumns: [new ORM\JoinColumn(name: 'campaign_id', referencedColumnName: 'id', onDelete: 'CASCADE')], inverseJoinColumns: [new ORM\JoinColumn(name: 'commande_id', referencedColumnName: 'id', onDelete: 'CASCADE')])]
    private Collection $commandes;

    #[ORM\Column(name: 'created_by', length: 255, nullable: true)]
    private ?string $createdBy = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->products = new ArrayCollection();
        $this->commandes = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }
    public function getChannel(): string { return $this->channel; }
    public function setChannel(string $channel): static { $this->channel = $channel; return $this; }
    public function getStartDate(): ?\DateTimeImmutable { return $this->startDate; }
    public function setStartDate(\DateTimeImmutable $d): static { $this->startDate = $d; return $this; }
    public function getEndDate(): ?\DateTimeImmutable { return $this->endDate; }
    public function setEndDate(\DateTimeImmutable $d): static { $this->endDate = $d; return $this; }
    public function getAmountCents(): int { return $this->amountCents; }
    public function setAmountCents(int $cents): static { $this->amountCents = $cents; return $this; }
    public function getCurrency(): ?string { return $this->currency; }
    public function setCurrency(?string $currency): static { $this->currency = $currency; return $this; }
    public function getExchangeRate(): ?float { return $this->exchangeRate === null ? null : (float) $this->exchangeRate; }
    public function setExchangeRate(?float $rate): static { $this->exchangeRate = $rate === null ? null : number_format($rate, 6, '.', ''); return $this; }
    /** Taux vers la devise du site (1 dans la devise du site) */
    public function rate(): float { return $this->currency === null ? 1.0 : (float) ($this->exchangeRate ?? 1); }
    /** Montant ramené dans la devise du site, en cents */
    public function amountSite(): int { return (int) round($this->amountCents * $this->rate()); }
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $notes): static { $this->notes = $notes; return $this; }
    public function getDocument(): ?GestionDocument { return $this->document; }
    public function setDocument(?GestionDocument $document): static { $this->document = $document; return $this; }
    /** @return Collection<int, Product> */
    public function getProducts(): Collection { return $this->products; }
    /** @return Collection<int, Commande> */
    public function getCommandes(): Collection { return $this->commandes; }
    public function getCreatedBy(): ?string { return $this->createdBy; }
    public function setCreatedBy(?string $by): static { $this->createdBy = $by; return $this; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $at): static { $this->createdAt = $at; return $this; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(\DateTimeImmutable $at): static { $this->updatedAt = $at; return $this; }
}

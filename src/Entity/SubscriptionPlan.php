<?php

namespace App\Entity;

use App\Repository\SubscriptionPlanRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Formule d'abonnement d'un produit (boutique réglable, 09/10/2026) : périodicité, prix en cents, essai gratuit,
 * engagement minimal ; le produit et le prix Stripe correspondants sont créés sur le compte connecté du site à la
 * première souscription. Noms par langue (names : { fr, en }).
 */
#[ORM\Entity(repositoryClass: SubscriptionPlanRepository::class)]
#[ORM\Table(name: 'subscription_plan')]
#[ORM\Index(columns: ['product_id'], name: 'idx_subscription_plan_product')]
class SubscriptionPlan
{
    public const INTERVALS = ['week', 'month', 'year'];

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(name: 'product_id', nullable: false, onDelete: 'CASCADE')]
    private ?Product $product = null;

    /** @var array<string, string> langue => nom */
    #[ORM\Column(type: Types::JSON)]
    private array $names = [];

    #[ORM\Column(length: 10)]
    private string $interval = 'month';

    #[ORM\Column(name: 'interval_count')]
    private int $intervalCount = 1;

    /** Cents */
    #[ORM\Column]
    private int $price = 0;

    #[ORM\Column(length: 3)]
    private string $currency = 'CAD';

    #[ORM\Column(name: 'trial_days')]
    private int $trialDays = 0;

    /** Nombre de périodes d'engagement minimal (0 = sans engagement) */
    #[ORM\Column(name: 'minimum_terms')]
    private int $minimumTerms = 0;

    #[ORM\Column(name: 'stripe_product_id', length: 64, nullable: true)]
    private ?string $stripeProductId = null;

    #[ORM\Column(name: 'stripe_price_id', length: 64, nullable: true)]
    private ?string $stripePriceId = null;

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getProduct(): ?Product { return $this->product; }
    public function setProduct(?Product $product): static { $this->product = $product; return $this; }
    /** @return array<string, string> */
    public function getNames(): array { return $this->names; }
    /** @param array<string, string> $names */
    public function setNames(array $names): static { $this->names = $names; return $this; }
    public function getName(string $locale = 'fr'): string { return (string) ($this->names[$locale] ?? $this->names['fr'] ?? reset($this->names) ?: ''); }
    public function getInterval(): string { return $this->interval; }
    public function setInterval(string $interval): static { $this->interval = $interval; return $this; }
    public function getIntervalCount(): int { return $this->intervalCount; }
    public function setIntervalCount(int $intervalCount): static { $this->intervalCount = max(1, $intervalCount); return $this; }
    public function getPrice(): int { return $this->price; }
    public function setPrice(int $price): static { $this->price = $price; return $this; }
    public function getCurrency(): string { return $this->currency; }
    public function setCurrency(string $currency): static { $this->currency = strtoupper($currency); return $this; }
    public function getTrialDays(): int { return $this->trialDays; }
    public function setTrialDays(int $trialDays): static { $this->trialDays = max(0, $trialDays); return $this; }
    public function getMinimumTerms(): int { return $this->minimumTerms; }
    public function setMinimumTerms(int $minimumTerms): static { $this->minimumTerms = max(0, $minimumTerms); return $this; }
    public function getStripeProductId(): ?string { return $this->stripeProductId; }
    public function setStripeProductId(?string $id): static { $this->stripeProductId = $id; return $this; }
    public function getStripePriceId(): ?string { return $this->stripePriceId; }
    public function setStripePriceId(?string $id): static { $this->stripePriceId = $id; return $this; }
    public function isActive(): bool { return $this->active; }
    public function setActive(bool $active): static { $this->active = $active; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function __toString(): string
    {
        return sprintf('%s (%s, %s)', $this->getName(), $this->product?->getName() ?? '?', $this->interval);
    }
}

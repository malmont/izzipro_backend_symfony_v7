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

    /** Avantages de la formule par langue (grille de formules, 09/10/2026) : {"fr": ["…", …], "en": […]}, 20 au plus, en clair */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $features = null;

    /** Sous-titre court par langue : {"fr": "Solution discount", "en": "…"} */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $descriptions = null;

    /** Texte du badge de la formule mise en avant, par langue (sinon le site affiche « Recommandé ») */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $badges = null;

    /** Formule recommandée, mise en avant dans la grille : une seule par produit (les autres sont décochées) */
    #[ORM\Column(options: ['default' => false])]
    private bool $highlighted = false;

    public const FEATURES_MAX = 20;
    public const FEATURE_LENGTH = 120;
    public const DESCRIPTION_LENGTH = 160;
    public const BADGE_LENGTH = 40;

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

    public function getFeatures(): ?array { return $this->features; }
    /** Listes par langue, nettoyées : texte en clair, vides retirés, 20 avantages de 120 caractères au plus */
    public function setFeatures(?array $features): static
    {
        $clean = [];
        foreach ($features ?? [] as $locale => $list) {
            if (!is_string($locale) || !is_array($list)) {
                continue;
            }
            $items = array_values(array_filter(array_map(fn ($t) => is_scalar($t) ? self::plain((string) $t, self::FEATURE_LENGTH) : '', $list), fn ($t) => $t !== ''));
            if ($items !== []) {
                $clean[$locale] = array_slice($items, 0, self::FEATURES_MAX);
            }
        }
        $this->features = $clean ?: null;

        return $this;
    }
    /** @return list<string> avantages dans la langue demandée, sinon en français */
    public function getFeatureList(string $locale = 'fr'): array { return $this->features[$locale] ?? $this->features['fr'] ?? []; }

    public function getDescriptions(): ?array { return $this->descriptions; }
    public function setDescriptions(?array $descriptions): static { $this->descriptions = self::texts($descriptions, self::DESCRIPTION_LENGTH); return $this; }
    public function getDescription(string $locale = 'fr'): ?string { return $this->descriptions[$locale] ?? $this->descriptions['fr'] ?? null; }

    public function getBadges(): ?array { return $this->badges; }
    public function setBadges(?array $badges): static { $this->badges = self::texts($badges, self::BADGE_LENGTH); return $this; }
    public function getBadge(string $locale = 'fr'): ?string { return $this->badges[$locale] ?? $this->badges['fr'] ?? null; }

    public function isHighlighted(): bool { return $this->highlighted; }
    public function setHighlighted(bool $highlighted): static { $this->highlighted = $highlighted; return $this; }

    /** Textes par langue nettoyés (en clair, longueur bornée, vides retirés) ; null s'il n'en reste aucun */
    private static function texts(?array $texts, int $max): ?array
    {
        $clean = [];
        foreach ($texts ?? [] as $locale => $text) {
            if (is_string($locale) && is_scalar($text) && ($value = self::plain((string) $text, $max)) !== '') {
                $clean[$locale] = $value;
            }
        }

        return $clean ?: null;
    }

    private static function plain(string $text, int $max): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'))), 0, $max);
    }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function __toString(): string
    {
        return sprintf('%s (%s, %s)', $this->getName(), $this->product?->getName() ?? '?', $this->interval);
    }
}

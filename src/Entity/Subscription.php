<?php

namespace App\Entity;

use App\Repository\SubscriptionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Abonnement d'un client à une formule (boutique réglable, 09/10/2026), miroir de l'abonnement Stripe Billing du
 * compte connecté du site : statut, fin de période, annulation programmée, adresse et transporteur d'un produit
 * physique. Les commandes créées à chaque facture payée pointent vers lui (order.subscription_id).
 */
#[ORM\Entity(repositoryClass: SubscriptionRepository::class)]
#[ORM\Table(name: 'subscription')]
#[ORM\Index(columns: ['user_id'], name: 'idx_subscription_user')]
#[ORM\Index(columns: ['stripe_subscription_id'], name: 'idx_subscription_stripe')]
class Subscription
{
    public const STATUS_INCOMPLETE = 'incomplete';
    public const STATUS_TRIALING = 'trialing';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAST_DUE = 'past_due';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_CANCELED = 'canceled';
    public const STATUSES = [self::STATUS_INCOMPLETE, self::STATUS_TRIALING, self::STATUS_ACTIVE, self::STATUS_PAST_DUE, self::STATUS_PAUSED, self::STATUS_CANCELED];

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: SubscriptionPlan::class)]
    #[ORM\JoinColumn(name: 'plan_id', nullable: false)]
    private ?SubscriptionPlan $plan = null;

    #[ORM\Column]
    private int $quantity = 1;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_INCOMPLETE;

    #[ORM\Column(name: 'current_period_end', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $currentPeriodEnd = null;

    #[ORM\Column(name: 'cancel_at_period_end')]
    private bool $cancelAtPeriodEnd = false;

    #[ORM\ManyToOne(targetEntity: Adress::class)]
    #[ORM\JoinColumn(name: 'address_id', nullable: true, onDelete: 'SET NULL')]
    private ?Adress $address = null;

    #[ORM\ManyToOne(targetEntity: Carrier::class)]
    #[ORM\JoinColumn(name: 'carrier_id', nullable: true, onDelete: 'SET NULL')]
    private ?Carrier $carrier = null;

    /** Livraison facturée à chaque échéance, en cents : prix fixe du transporteur à la souscription (0 sans transporteur ou gratuit) */
    #[ORM\Column(name: 'shipping_amount', options: ['default' => 0])]
    private int $shippingAmount = 0;

    #[ORM\Column(name: 'stripe_subscription_id', length: 64, nullable: true)]
    private ?string $stripeSubscriptionId = null;

    #[ORM\Column(name: 'stripe_customer_id', length: 64, nullable: true)]
    private ?string $stripeCustomerId = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'canceled_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $canceledAt = null;

    #[ORM\Column(name: 'paused_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $pausedAt = null;

    public function __construct()
    {
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): static { $this->user = $user; return $this; }
    public function getPlan(): ?SubscriptionPlan { return $this->plan; }
    public function setPlan(?SubscriptionPlan $plan): static { $this->plan = $plan; return $this; }
    public function getQuantity(): int { return $this->quantity; }
    public function setQuantity(int $quantity): static { $this->quantity = max(1, $quantity); return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; $this->touch(); return $this; }
    public function getCurrentPeriodEnd(): ?\DateTimeImmutable { return $this->currentPeriodEnd; }
    public function setCurrentPeriodEnd(?\DateTimeImmutable $end): static { $this->currentPeriodEnd = $end; return $this; }
    public function isCancelAtPeriodEnd(): bool { return $this->cancelAtPeriodEnd; }
    public function setCancelAtPeriodEnd(bool $flag): static { $this->cancelAtPeriodEnd = $flag; $this->touch(); return $this; }
    public function getAddress(): ?Adress { return $this->address; }
    public function setAddress(?Adress $address): static { $this->address = $address; return $this; }
    public function getCarrier(): ?Carrier { return $this->carrier; }
    public function setCarrier(?Carrier $carrier): static { $this->carrier = $carrier; return $this; }
    public function getShippingAmount(): int { return $this->shippingAmount; }
    public function setShippingAmount(int $cents): static { $this->shippingAmount = max(0, $cents); return $this; }
    public function getStripeSubscriptionId(): ?string { return $this->stripeSubscriptionId; }
    public function setStripeSubscriptionId(?string $id): static { $this->stripeSubscriptionId = $id; return $this; }
    public function getStripeCustomerId(): ?string { return $this->stripeCustomerId; }
    public function setStripeCustomerId(?string $id): static { $this->stripeCustomerId = $id; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function getCanceledAt(): ?\DateTimeImmutable { return $this->canceledAt; }
    public function setCanceledAt(?\DateTimeImmutable $at): static { $this->canceledAt = $at; return $this; }
    public function getPausedAt(): ?\DateTimeImmutable { return $this->pausedAt; }
    public function setPausedAt(?\DateTimeImmutable $at): static { $this->pausedAt = $at; return $this; }
    public function touch(): static { $this->updatedAt = new \DateTimeImmutable(); return $this; }

    public function __toString(): string
    {
        return sprintf('Abonnement #%d (%s)', $this->id ?? 0, $this->status);
    }
}

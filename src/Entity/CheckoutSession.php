<?php

namespace App\Entity;

use App\Repository\CheckoutSessionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Paiement d'un panier en cours (09/10/2026) : un intent Stripe, le panier qui l'a chiffré et les données de commande
 * envoyées par le navigateur. La commande naît une seule fois, du navigateur (order/create, create-guest) ou, s'il ne
 * l'a jamais fait, du webhook Stripe : la réservation atomique open → processing départage les deux.
 */
#[ORM\Entity(repositoryClass: CheckoutSessionRepository::class)]
#[ORM\Table(name: 'checkout_session')]
#[ORM\UniqueConstraint(name: 'uniq_checkout_payment_intent', columns: ['payment_intent_id'])]
class CheckoutSession
{
    public const STATUS_OPEN = 'open';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_ORDERED = 'ordered';
    public const STATUS_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'payment_intent_id', length: 64)]
    private string $paymentIntentId;

    #[ORM\Column(length: 12, options: ['default' => self::STATUS_OPEN])]
    private string $status = self::STATUS_OPEN;

    /** Client connecté qui a ouvert le paiement (null : invité) */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    /** Panier chiffré (corps de create-intent : items, carrierId, shippingAddress, priceShipping…) */
    #[ORM\Column(type: Types::JSON)]
    private array $cart = [];

    /** Corps de la commande que le navigateur enverra (order/create ou create-guest), pour le webhook ; null si inconnu */
    #[ORM\Column(name: 'order_data', type: Types::JSON, nullable: true)]
    private ?array $orderData = null;

    #[ORM\Column]
    private int $amount = 0;

    #[ORM\Column(length: 3)]
    private string $currency = 'cad';

    #[ORM\Column(length: 5, options: ['default' => 'fr'])]
    private string $locale = 'fr';

    /** Adresse publique du site (logos et liens des courriels envoyés par le webhook) */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $host = null;

    #[ORM\ManyToOne(targetEntity: Order::class)]
    #[ORM\JoinColumn(name: 'order_id', nullable: true, onDelete: 'SET NULL')]
    private ?Order $order = null;

    #[ORM\Column(name: 'last_error', length: 255, nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $paymentIntentId)
    {
        $this->paymentIntentId = $paymentIntentId;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int { return $this->id; }
    public function getPaymentIntentId(): string { return $this->paymentIntentId; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this->touch(); }
    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): static { $this->user = $user; return $this; }
    public function getCart(): array { return $this->cart; }
    public function setCart(array $cart): static { $this->cart = $cart; return $this->touch(); }
    public function getOrderData(): ?array { return $this->orderData; }
    public function setOrderData(?array $data): static { $this->orderData = $data; return $this->touch(); }
    public function getAmount(): int { return $this->amount; }
    public function setAmount(int $amount): static { $this->amount = $amount; return $this->touch(); }
    public function getCurrency(): string { return $this->currency; }
    public function setCurrency(string $currency): static { $this->currency = strtolower($currency); return $this; }
    public function getLocale(): string { return $this->locale; }
    public function setLocale(string $locale): static { $this->locale = $locale; return $this; }
    public function getHost(): ?string { return $this->host; }
    public function setHost(?string $host): static { $this->host = $host; return $this; }
    public function getOrder(): ?Order { return $this->order; }
    public function setOrder(?Order $order): static { $this->order = $order; return $this->touch(); }
    public function getLastError(): ?string { return $this->lastError; }
    public function setLastError(?string $error): static { $this->lastError = $error !== null ? mb_substr($error, 0, 255) : null; return $this->touch(); }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    private function touch(): static
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}

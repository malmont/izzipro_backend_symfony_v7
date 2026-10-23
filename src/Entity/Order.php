<?php

namespace App\Entity;

use App\Repository\OrderRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OrderRepository::class)]
#[ORM\Table(name: '`order`')]
class Order
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $reference = null;

    #[ORM\ManyToOne(inversedBy: 'userOrders')]
    private ?User $userId = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $orderDate = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $statusUpdatedAt = null;

    #[ORM\Column]
    private ?float $totalAmount = 0;

    #[ORM\ManyToOne(inversedBy: 'orders')]
    private ?Adress $shippingAdress = null;

    #[ORM\ManyToOne(inversedBy: 'orders')]
    private ?OrderSource $orderSource = null;

    /**
     * @var Collection<int, OrderItems>
     */
    #[ORM\OneToMany(mappedBy: 'orderAssociated', targetEntity: OrderItems::class)]
    private Collection $orderItems;

    /**
     * @var Collection<int, Payments>
     */
    #[ORM\OneToMany(mappedBy: 'orderPayment', targetEntity: Payments::class)]
    private Collection $payments;

    /**
     * @var Collection<int, TransactionCaisse>
     */
    #[ORM\OneToMany(mappedBy: 'orderCaisse', targetEntity: TransactionCaisse::class)]
    private Collection $transactionCaisses;

    #[ORM\ManyToOne(inversedBy: 'orders')]
    private ?Carrier $carrier = null;

    #[ORM\ManyToOne(inversedBy: 'orders')]
    #[ORM\JoinColumn(nullable: false)]
    private ?StatusCommande $status = null;

    #[ORM\Column(nullable: true)]
    private ?float $subTotal = null;

    #[ORM\Column(nullable: true)]
    private ?float $total_tax = null;

    /**
     * @var Collection<int, OrderTax>
     */
    #[ORM\OneToMany(mappedBy: 'orderTax', targetEntity: OrderTax::class)]
    private Collection $orderTaxes;

    #[ORM\ManyToOne(inversedBy: 'orders')]
    private ?OrderType $orderType = null;

    #[ORM\OneToOne(mappedBy: 'odershipping', cascade: ['persist', 'remove'])]
    private ?ShippingOrder $shippingOrder = null;

    #[ORM\Column(nullable: true)]
    private ?float $shippingCost = null;

    // Arrondi de la part payée en espèces (cents, signé ; vente en caisse, 19/10/2026) : somme des paiements = total + arrondi
    #[ORM\Column(options: ['default' => 0])]
    private int $cashRounding = 0;

    // Retours partiels et échanges en caisse (F2, 24/10/2026) : vente d'origine d'une commande de retour ; crédit d'un retour
    // appliqué à une nouvelle vente (cents) : les paiements couvrent total + arrondi - crédit
    #[ORM\ManyToOne(targetEntity: Order::class)]
    #[ORM\JoinColumn(name: 'returned_from_id', nullable: true, onDelete: 'SET NULL')]
    private ?Order $returnedFrom = null;

    #[ORM\Column(nullable: true)]
    private ?int $creditApplied = null;

    /** Part réglée par carte-cadeau ou avoir (F9, 27/10/2026, cents) : aucune ligne dans payments, comme le crédit d'échange */
    #[ORM\Column(nullable: true)]
    private ?int $giftCardApplied = null;

    /** Client de caisse rattaché (F8, 28/10/2026 : pos_customer ; user_id reste le caissier pour une vente en caisse) */
    #[ORM\Column(nullable: true)]
    private ?int $customerId = null;

    // Remise de ticket de caisse (F1, 24/10/2026) : montant total réparti sur les lignes, pourcentage en centièmes de % si remise en
    // pourcentage, motif ; 0 / null : aucune remise, comme avant
    #[ORM\Column(options: ['default' => 0])]
    private int $discountAmount = 0;

    #[ORM\Column(nullable: true)]
    private ?int $discountPercentBp = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $discountReasonCode = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $discountReasonText = null;

    // Vente hors ligne synchronisée (C5 partie 2, 25/10/2026) : order_date = heure de vente corrigée, synced_at = enregistrement au serveur
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $syncedAt = null;

    // Non enregistré en base : poste de caisse choisi dans EasyAdmin pour une vente au comptoir créée à la main (C2)
    private ?int $posCashRegisterId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $guestLicenseNumber = null;

    #[ORM\Column(type: 'date', nullable: true)]
    private ?\DateTimeInterface $guestLicenseExpirationDate = null;

    /** Jeton d'accès d'un invité à sa commande (GET /api/orders/{id}?token=), renvoyé par order/create-guest ; 08/10/2026 */
    #[ORM\Column(name: 'guest_token', length: 64, nullable: true)]
    private ?string $guestToken = null;

    /** Transaction fiscale Stripe Tax enregistrée pour cette commande (taxes par région, 09/10/2026) */
    #[ORM\Column(name: 'tax_transaction_id', length: 64, nullable: true)]
    private ?string $taxTransactionId = null;

    /** Abonnement dont cette commande est une échéance (facture Stripe stripe_invoice_id) ; boutique réglable, 09/10/2026 */
    #[ORM\ManyToOne(targetEntity: Subscription::class)]
    #[ORM\JoinColumn(name: 'subscription_id', nullable: true, onDelete: 'SET NULL')]
    private ?Subscription $subscription = null;

    #[ORM\Column(name: 'stripe_invoice_id', length: 64, nullable: true)]
    private ?string $stripeInvoiceId = null;

    /** Calcul Stripe Tax de la commande en cours (non enregistré) : à transformer en transaction après le paiement */
    private ?string $pendingTaxCalculationId = null;

    /** Taxes calculées par le devis de la commande en cours (non enregistré) : TaxCalculationService en fait les lignes OrderTax */
    private ?array $pendingTaxes = null;

    public function __construct()
    {
        $this->orderItems = new ArrayCollection();
        $this->payments = new ArrayCollection();
        $this->transactionCaisses = new ArrayCollection();
        $this->orderTaxes = new ArrayCollection();

    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(string $reference): static
    {
        $this->reference = $reference;

        return $this;
    }

    public function getUserId(): ?User
    {
        return $this->userId;
    }

    public function setUserId(?User $userId): static
    {
        $this->userId = $userId;

        return $this;
    }

    public function getOrderDate(): ?\DateTimeInterface
    {
        return $this->orderDate;
    }

    public function setOrderDate(\DateTimeInterface $orderDate): static
    {
        $this->orderDate = $orderDate;

        return $this;
    }
    public function getStatusUpdatedAt(): ?\DateTimeInterface
    {
        return $this->statusUpdatedAt;
    }

    public function setStatusUpdatedAt(\DateTimeInterface $statusUpdatedAt): static
    {
        $this->statusUpdatedAt = $statusUpdatedAt;

        return $this;
    }
    public function getTotalAmount(): ?float
    {
        return $this->totalAmount;
    }

    public function setTotalAmount(float $totalAmount): static
    {
        $this->totalAmount = $totalAmount;

        return $this;
    }

    public function getShippingAdress(): ?Adress
    {
        return $this->shippingAdress;
    }

    public function setShippingAdress(?Adress $shippingAdress): static
    {
        $this->shippingAdress = $shippingAdress;

        return $this;
    }

    public function getOrderSource(): ?OrderSource
    {
        return $this->orderSource;
    }

    public function setOrderSource(?OrderSource $orderSource): static
    {
        $this->orderSource = $orderSource;

        return $this;
    }

    /**
     * @return Collection<int, OrderItems>
     */
    public function getOrderItems(): Collection
    {
        return $this->orderItems;
    }

    public function addOrderItem(OrderItems $orderItem): static
    {
        if (!$this->orderItems->contains($orderItem)) {
            $this->orderItems->add($orderItem);
            $orderItem->setOrderAssociated($this);
        }

        return $this;
    }

    public function removeOrderItem(OrderItems $orderItem): static
    {
        if ($this->orderItems->removeElement($orderItem)) {
            if ($orderItem->getOrderAssociated() === $this) {
                $orderItem->setOrderAssociated(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Payments>
     */
    public function getPayments(): Collection
    {
        return $this->payments;
    }

    public function addPayment(Payments $payment): static
    {
        if (!$this->payments->contains($payment)) {
            $this->payments->add($payment);
            $payment->setOrderPayment($this);
        }

        return $this;
    }

    public function removePayment(Payments $payment): static
    {
        if ($this->payments->removeElement($payment)) {
            if ($payment->getOrderPayment() === $this) {
                $payment->setOrderPayment(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, TransactionCaisse>
     */
    public function getTransactionCaisses(): Collection
    {
        return $this->transactionCaisses;
    }

    public function addTransactionCaiss(TransactionCaisse $transactionCaiss): static
    {
        if (!$this->transactionCaisses->contains($transactionCaiss)) {
            $this->transactionCaisses->add($transactionCaiss);
            $transactionCaiss->setOrderCaisse($this);
        }

        return $this;
    }

    public function removeTransactionCaiss(TransactionCaisse $transactionCaiss): static
    {
        if ($this->transactionCaisses->removeElement($transactionCaiss)) {
            // set the owning side to null (unless already changed)
            if ($transactionCaiss->getOrderCaisse() === $this) {
                $transactionCaiss->setOrderCaisse(null);
            }
        }

        return $this;
    }

    public function getCarrier(): ?Carrier
    {
        return $this->carrier;
    }

    public function setCarrier(?Carrier $carrier): static
    {
        $this->carrier = $carrier;

        return $this;
    }

    public function getStatus(): ?StatusCommande
    {
        return $this->status;
    }

    public function setStatus(?StatusCommande $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function __toString(): string
    {
        return $this->reference ?? 'N/A';
    }

    public function getSubTotal(): ?float
    {
        return $this->subTotal;
    }

    public function setSubTotal(?float $subTotal): static
    {
        $this->subTotal = $subTotal;

        return $this;
    }

    public function getTotalTax(): ?float
    {
        return $this->total_tax;
    }

    public function setTotalTax(?float $total_tax): static
    {
        $this->total_tax = $total_tax;

        return $this;
    }

    /**
     * @return Collection<int, OrderTax>
     */
    public function getOrderTaxes(): Collection
    {
        return $this->orderTaxes;
    }

    public function addOrderTax(OrderTax $orderTax): static
    {
        if (!$this->orderTaxes->contains($orderTax)) {
            $this->orderTaxes->add($orderTax);
            $orderTax->setOrderTax($this);
        }

        return $this;
    }

    public function removeOrderTax(OrderTax $orderTax): static
    {
        if ($this->orderTaxes->removeElement($orderTax)) {
            if ($orderTax->getOrderTax() === $this) {
                $orderTax->setOrderTax(null);
            }
        }

        return $this;
    }

    public function getOrderType(): ?OrderType
    {
        return $this->orderType;
    }

    public function setOrderType(?OrderType $orderType): static
    {
        $this->orderType = $orderType;

        return $this;
    }

    public function getShippingOrder(): ?ShippingOrder
    {
        return $this->shippingOrder;
    }

    public function setShippingOrder(?ShippingOrder $shippingOrder): static
    {
        if ($shippingOrder === null && $this->shippingOrder !== null) {
            $this->shippingOrder->setOdershipping(null);
        }
        if ($shippingOrder !== null && $shippingOrder->getOdershipping() !== $this) {
            $shippingOrder->setOdershipping($this);
        }

        $this->shippingOrder = $shippingOrder;

        return $this;
    }

    public function getPosCashRegisterId(): ?int
    {
        return $this->posCashRegisterId;
    }

    public function setPosCashRegisterId(?int $posCashRegisterId): static
    {
        $this->posCashRegisterId = $posCashRegisterId;

        return $this;
    }

    public function getCashRounding(): int
    {
        return $this->cashRounding;
    }

    public function getSyncedAt(): ?\DateTimeImmutable { return $this->syncedAt; }
    public function setSyncedAt(?\DateTimeImmutable $at): static { $this->syncedAt = $at; return $this; }
    public function getDiscountAmount(): int { return $this->discountAmount; }
    public function setDiscountAmount(int $cents): static { $this->discountAmount = max(0, $cents); return $this; }
    public function getDiscountPercentBp(): ?int { return $this->discountPercentBp; }
    public function setDiscountPercentBp(?int $bp): static { $this->discountPercentBp = $bp; return $this; }
    public function getDiscountReasonCode(): ?string { return $this->discountReasonCode; }
    public function setDiscountReasonCode(?string $c): static { $this->discountReasonCode = $c !== null ? mb_substr($c, 0, 40) : null; return $this; }
    public function getDiscountReasonText(): ?string { return $this->discountReasonText; }
    public function setDiscountReasonText(?string $t): static { $t = $t !== null ? trim(strip_tags($t)) : ''; $this->discountReasonText = $t === '' ? null : mb_substr($t, 0, 500); return $this; }
    public function getReturnedFrom(): ?Order { return $this->returnedFrom; }
    public function setReturnedFrom(?Order $order): static { $this->returnedFrom = $order; return $this; }
    public function getCreditApplied(): int { return $this->creditApplied ?? 0; }
    public function setCreditApplied(?int $cents): static { $this->creditApplied = $cents !== null && $cents > 0 ? $cents : null; return $this; }
    public function getGiftCardApplied(): int { return $this->giftCardApplied ?? 0; }
    public function getCustomerId(): ?int { return $this->customerId; }
    public function setCustomerId(?int $id): static { $this->customerId = $id; return $this; }
    public function setGiftCardApplied(?int $cents): static { $this->giftCardApplied = $cents !== null && $cents > 0 ? $cents : null; return $this; }

    public function setCashRounding(int $cashRounding): static
    {
        $this->cashRounding = $cashRounding;

        return $this;
    }

    public function getShippingCost(): ?float
    {
        return $this->shippingCost;
    }

    public function setShippingCost(?float $shippingCost): self
    {
        $this->shippingCost = $shippingCost;

        return $this;
    }

  

    public function getGuestLicenseNumber(): ?string
    {
        return $this->guestLicenseNumber;
    }

    public function setGuestLicenseNumber(?string $guestLicenseNumber): self
    {
        $this->guestLicenseNumber = $guestLicenseNumber;
        return $this;
    }

    public function getGuestLicenseExpirationDate(): ?\DateTimeInterface
    {
        return $this->guestLicenseExpirationDate;
    }

    public function setGuestLicenseExpirationDate(?\DateTimeInterface $guestLicenseExpirationDate): self
    {
        $this->guestLicenseExpirationDate = $guestLicenseExpirationDate;
        return $this;
    }

    public function getGuestToken(): ?string
    {
        return $this->guestToken;
    }

    public function setGuestToken(?string $guestToken): static
    {
        $this->guestToken = $guestToken;

        return $this;
    }

    public function getTaxTransactionId(): ?string
    {
        return $this->taxTransactionId;
    }

    public function setTaxTransactionId(?string $taxTransactionId): static
    {
        $this->taxTransactionId = $taxTransactionId;

        return $this;
    }

    public function getPendingTaxCalculationId(): ?string
    {
        return $this->pendingTaxCalculationId;
    }

    public function setPendingTaxCalculationId(?string $calculationId): static
    {
        $this->pendingTaxCalculationId = $calculationId;

        return $this;
    }

    /** @return list<array{label: string, rate: float, amount: int, taxId?: int}>|null */
    public function getPendingTaxes(): ?array
    {
        return $this->pendingTaxes;
    }

    public function setPendingTaxes(?array $taxes): static
    {
        $this->pendingTaxes = $taxes;

        return $this;
    }

    public function getSubscription(): ?Subscription
    {
        return $this->subscription;
    }

    public function setSubscription(?Subscription $subscription): static
    {
        $this->subscription = $subscription;

        return $this;
    }

    public function getStripeInvoiceId(): ?string
    {
        return $this->stripeInvoiceId;
    }

    public function setStripeInvoiceId(?string $stripeInvoiceId): static
    {
        $this->stripeInvoiceId = $stripeInvoiceId;

        return $this;
    }
}

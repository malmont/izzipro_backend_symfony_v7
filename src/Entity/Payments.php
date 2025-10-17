<?php

namespace App\Entity;

use App\Repository\PaymentsRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PaymentsRepository::class)]
class Payments
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'payments')]
    private ?Order $orderPayment = null;

    #[ORM\Column]
    private ?float $amount = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $paymentDate = null;

    #[ORM\ManyToOne(inversedBy: 'payments')]
    private ?PaymentMethod $paymentMethod = null;

    #[ORM\ManyToOne(inversedBy: 'payments')]
    private ?StatusPayment $statutPayment = null;

    /**
     * @var Collection<int, TransactionCaisse>
     */
    #[ORM\OneToMany(mappedBy: 'payment', targetEntity: TransactionCaisse::class)]
    private Collection $transactionCaisses;

    #[ORM\ManyToOne(inversedBy: 'payments')]
    private ?PaymentType $paymentType = null;

    // 🔑 ID du paiement Square
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $squarePaymentId = null;

    // 🛒 ID de la commande Square
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $squareOrderId = null;

    // 🧾 URL du reçu
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $squareReceiptUrl = null;

    // 📊 Statut du paiement Square
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $squareStatus = null;

    // 💳 Marque de la carte (VISA, MASTERCARD...)
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $squareCardBrand = null;

    // 🔢 4 derniers chiffres de la carte
    #[ORM\Column(length: 4, nullable: true)]
    private ?string $squareLast4 = null;

    // 🔒 Niveau de risque
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $squareRiskLevel = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripePaymentId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripeReceiptUrl = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $stripeStatus = null;
    
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $stripeCardBrand = null;

    #[ORM\Column(length: 4, nullable: true)]
    private ?string $stripeLast4 = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $stripeRiskLevel = null;

    public function __construct()
    {
        $this->transactionCaisses = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrderPayment(): ?Order
    {
        return $this->orderPayment;
    }

    public function setOrderPayment(?Order $orderPayment): static
    {
        $this->orderPayment = $orderPayment;

        return $this;
    }

    public function getAmount(): ?float
    {
        return $this->amount;
    }

    public function setAmount(float $amount): static
    {
        $this->amount = $amount;

        return $this;
    }

    public function getPaymentDate(): ?\DateTimeInterface
    {
        return $this->paymentDate;
    }

    public function setPaymentDate(\DateTimeInterface $paymentDate): static
    {
        $this->paymentDate = $paymentDate;

        return $this;
    }

    public function getPaymentMethod(): ?PaymentMethod
    {
        return $this->paymentMethod;
    }

    public function setPaymentMethod(?PaymentMethod $paymentMethod): static
    {
        $this->paymentMethod = $paymentMethod;

        return $this;
    }

    public function getStatutPayment(): ?StatusPayment
    {
        return $this->statutPayment;
    }

    public function setStatutPayment(?StatusPayment $statutPayment): static
    {
        $this->statutPayment = $statutPayment;

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
            $transactionCaiss->setPayment($this);
        }

        return $this;
    }

    public function removeTransactionCaiss(TransactionCaisse $transactionCaiss): static
    {
        if ($this->transactionCaisses->removeElement($transactionCaiss)) {
            // set the owning side to null (unless already changed)
            if ($transactionCaiss->getPayment() === $this) {
                $transactionCaiss->setPayment(null);
            }
        }

        return $this;
    }

    public function getPaymentType(): ?PaymentType
    {
        return $this->paymentType;
    }

    public function setPaymentType(?PaymentType $paymentType): static
    {
        $this->paymentType = $paymentType;

        return $this;
    }
    public function __toString(): string
    {
        return (string)$this->id;
    }


    public function getSquarePaymentId(): ?string
    {
        return $this->squarePaymentId;
    }

    public function setSquarePaymentId(?string $squarePaymentId): self
    {
        $this->squarePaymentId = $squarePaymentId;
        return $this;
    }

    public function getSquareOrderId(): ?string
    {
        return $this->squareOrderId;
    }

    public function setSquareOrderId(?string $squareOrderId): self
    {
        $this->squareOrderId = $squareOrderId;
        return $this;
    }

    public function getSquareReceiptUrl(): ?string
    {
        return $this->squareReceiptUrl;
    }

    public function setSquareReceiptUrl(?string $squareReceiptUrl): self
    {
        $this->squareReceiptUrl = $squareReceiptUrl;
        return $this;
    }

    public function getSquareStatus(): ?string
    {
        return $this->squareStatus;
    }

    public function setSquareStatus(?string $squareStatus): self
    {
        $this->squareStatus = $squareStatus;
        return $this;
    }

    public function getSquareCardBrand(): ?string
    {
        return $this->squareCardBrand;
    }

    public function setSquareCardBrand(?string $squareCardBrand): self
    {
        $this->squareCardBrand = $squareCardBrand;
        return $this;
    }

    public function getSquareLast4(): ?string
    {
        return $this->squareLast4;
    }

    public function setSquareLast4(?string $squareLast4): self
    {
        $this->squareLast4 = $squareLast4;
        return $this;
    }

    public function getSquareRiskLevel(): ?string
    {
        return $this->squareRiskLevel;
    }

    public function setSquareRiskLevel(?string $squareRiskLevel): self
    {
        $this->squareRiskLevel = $squareRiskLevel;
        return $this;
    }

    public function getStripePaymentId(): ?string { return $this->stripePaymentId; }
    public function setStripePaymentId(?string $stripePaymentId): self { $this->stripePaymentId = $stripePaymentId; return $this; }
    public function getStripeReceiptUrl(): ?string { return $this->stripeReceiptUrl; }
    public function setStripeReceiptUrl(?string $stripeReceiptUrl): self { $this->stripeReceiptUrl = $stripeReceiptUrl; return $this; }
    public function getStripeStatus(): ?string { return $this->stripeStatus; }
    public function setStripeStatus(?string $stripeStatus): self { $this->stripeStatus = $stripeStatus; return $this; }
    public function getStripeCardBrand(): ?string { return $this->stripeCardBrand; }
    public function setStripeCardBrand(?string $stripeCardBrand): self { $this->stripeCardBrand = $stripeCardBrand; return $this; }
    public function getStripeLast4(): ?string { return $this->stripeLast4; }
    public function setStripeLast4(?string $stripeLast4): self { $this->stripeLast4 = $stripeLast4; return $this; }
    public function getStripeRiskLevel(): ?string { return $this->stripeRiskLevel; }
    public function setStripeRiskLevel(?string $stripeRiskLevel): self { $this->stripeRiskLevel = $stripeRiskLevel; return $this; }
 
}

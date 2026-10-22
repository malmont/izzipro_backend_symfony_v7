<?php

namespace App\Dto;

use App\Dto\ICreateOrderDTO;

class CreateOrderMultiPaymentDTO implements ICreateOrderDTO
{
    private $userId;
    private $orderSource;
    private $paymentMethods;
    private $addressId;
    private $carrierId;
    private $typeOrder;
    private $items;
    private $priceShipping;

    // 🔑 Infos de paiement Square
    private ?string $squarePaymentId;
    private ?string $squareOrderId;
    private ?string $squareReceiptUrl;
    private ?string $squareStatus;
    private ?string $squareCardBrand;
    private ?string $squareLast4;
    private ?string $squareRiskLevel;
    // 🔑 Infos de paiement Stripe
    private ?string $stripePaymentId;
    private ?string $stripeReceiptUrl;
    private ?string $stripeStatus;
    private ?string $stripeCardBrand;
    private ?string $stripeLast4;
    private ?string $stripeRiskLevel;
    private ?string $guestLicenseNumber;
    private ?string $guestLicenseExpirationDate;
    // Somme des moyens de paiement (cents) posée par la route caisse : le total calculé doit lui correspondre
    private ?int $verifiedPaymentAmount = null;
    // Poste de caisse de la vente (15/10/2026) ; null : poste unique du site (CashRegisterResolver)
    private ?int $cashRegisterId = null;
    // Parts espèces et non espèces des paiements (cents, signées ; route caisse, 19/10/2026) : contrôle avec arrondi
    private ?int $cashCents = null;
    /** Échange (F2) : crédit d'un retour appliqué à la vente (cents) ; les paiements couvrent total + arrondi - crédit */
    private int $creditApplied = 0;
    private ?int $nonCashCents = null;

    public function __construct(
        int $userId,
        int $orderSource,
        array $paymentMethods,
        ?int $addressId,
        ?int $carrierId,
        int $typeOrder,
        array $items,
        ?float $priceShipping = null,
        ?string $squarePaymentId = null,
        ?string $squareOrderId = null,
        ?string $squareReceiptUrl = null,
        ?string $squareStatus = null,
        ?string $squareCardBrand = null,
        ?string $squareLast4 = null,
        ?string $squareRiskLevel = null,
        ?string $stripePaymentId = null,
        ?string $stripeReceiptUrl = null,
        ?string $stripeStatus = null,
        ?string $stripeCardBrand = null,
        ?string $stripeLast4 = null,
        ?string $stripeRiskLevel = null,
        ?string $guestLicenseNumber = null,
        ?string $guestLicenseExpirationDate = null
    ) {
        $this->userId = $userId;
        $this->orderSource = $orderSource;
        $this->paymentMethods = $paymentMethods;
        $this->addressId = $addressId;
        $this->carrierId = $carrierId;
        $this->typeOrder = $typeOrder;
        $this->items = $items;
        $this->priceShipping = $priceShipping;

        // 🟢 Initialisation des données de paiement Square
        $this->squarePaymentId = $squarePaymentId;
        $this->squareOrderId = $squareOrderId;
        $this->squareReceiptUrl = $squareReceiptUrl;
        $this->squareStatus = $squareStatus;
        $this->squareCardBrand = $squareCardBrand;
        $this->squareLast4 = $squareLast4;
        $this->squareRiskLevel = $squareRiskLevel;

        // 🟢 Initialisation des données de paiement Stripe
        $this->stripePaymentId = $stripePaymentId;
        $this->stripeReceiptUrl = $stripeReceiptUrl;
        $this->stripeStatus = $stripeStatus;
        $this->stripeCardBrand = $stripeCardBrand;
        $this->stripeLast4 = $stripeLast4;
        $this->stripeRiskLevel = $stripeRiskLevel;
        $this->guestLicenseNumber = $guestLicenseNumber;
        $this->guestLicenseExpirationDate = $guestLicenseExpirationDate;
    }

    // ✅ Getters existants
    public function getUserId(): int
    {
        return $this->userId;
    }
    public function getOrderSource(): int
    {
        return $this->orderSource;
    }
    public function getPaymentMethods(): array
    {
        return $this->paymentMethods;
    }
    public function getAddressId(): ?int
    {
        return $this->addressId;
    }
    public function getCarrierId(): ?int
    {
        return $this->carrierId;
    }
    public function getTypeOrder(): int
    {
        return $this->typeOrder;
    }
    public function getItems(): array
    {
        return $this->items;
    }
    public function getPriceShipping(): ?float
    {
        return $this->priceShipping;
    }

    public function getPaymentMethod(): ?int
    {
        return null;
    }

    public function setVerifiedPaymentAmount(?int $amount): self
    {
        $this->verifiedPaymentAmount = $amount;
        return $this;
    }

    public function getVerifiedPaymentAmount(): ?int
    {
        return $this->verifiedPaymentAmount;
    }

    public function setCashRegisterId(?int $cashRegisterId): self
    {
        $this->cashRegisterId = $cashRegisterId;
        return $this;
    }

    public function getCashRegisterId(): ?int
    {
        return $this->cashRegisterId;
    }

    public function setPaymentSplit(int $cashCents, int $nonCashCents): self
    {
        $this->cashCents = $cashCents;
        $this->nonCashCents = $nonCashCents;
        return $this;
    }

    public function getCashCents(): ?int
    {
        return $this->cashCents;
    }

    public function getNonCashCents(): ?int
    {
        return $this->nonCashCents;
    }

    // ✅ Getters pour les données de paiement Square
    public function getSquarePaymentId(): ?string
    {
        return $this->squarePaymentId;
    }
    public function getSquareOrderId(): ?string
    {
        return $this->squareOrderId;
    }
    public function getSquareReceiptUrl(): ?string
    {
        return $this->squareReceiptUrl;
    }
    public function getSquareStatus(): ?string
    {
        return $this->squareStatus;
    }
    public function getSquareCardBrand(): ?string
    {
        return $this->squareCardBrand;
    }
    public function getSquareLast4(): ?string
    {
        return $this->squareLast4;
    }
    public function getSquareRiskLevel(): ?string
    {
        return $this->squareRiskLevel;
    }

    // ✅ Getters pour les données de paiement Stripe
    public function getStripePaymentId(): ?string
    {
        return $this->stripePaymentId;
    }
    public function getStripeReceiptUrl(): ?string
    {
        return $this->stripeReceiptUrl;
    }
    public function getStripeStatus(): ?string
    {
        return $this->stripeStatus;
    }
    public function getStripeCardBrand(): ?string
    {
        return $this->stripeCardBrand;
    }
    public function getStripeLast4(): ?string
    {
        return $this->stripeLast4;
    }
    public function getStripeRiskLevel(): ?string
    {
        return $this->stripeRiskLevel;
    }

    public function getGuestLicenseNumber(): ?string
    {
        return $this->guestLicenseNumber;
    }

    public function getGuestLicenseExpirationDate(): ?string
    {
        return $this->guestLicenseExpirationDate;
    }
    public function getCreditApplied(): int { return $this->creditApplied; }
    public function setCreditApplied(int $cents): static { $this->creditApplied = max(0, $cents); return $this; }
}

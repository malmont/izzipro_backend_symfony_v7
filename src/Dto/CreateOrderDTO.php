<?php

namespace App\Dto;

use App\Dto\ICreateOrderDTO;

class CreateOrderDTO implements ICreateOrderDTO
{
    private int $userId;
    private int $orderSource;
    private int $paymentMethod;
    private int $addressId;

    // CORRECTION 1 : Ajout du '?' pour accepter null
    private ?int $carrierId;

    private int $typeOrder;
    private array $items;
    private ?float $priceShipping;

    // 🔑 Infos liées au paiement Square
    private ?string $squarePaymentId;
    private ?string $squareOrderId;
    private ?string $squareReceiptUrl;
    private ?string $squareStatus;
    private ?string $squareCardBrand;
    private ?string $squareLast4;
    private ?string $squareRiskLevel;

    // --- ✅ NOUVELLES PROPRIÉTÉS POUR STRIPE ---
    private ?string $stripePaymentId;
    private ?string $stripeReceiptUrl;
    private ?string $stripeStatus;
    private ?string $stripeCardBrand;
    private ?string $stripeLast4;
    private ?string $stripeRiskLevel;

    public function __construct(
        int $userId,
        int $orderSource,
        int $paymentMethod,
        int $addressId,

        // CORRECTION 2 : Ajout du '?' ici aussi dans le constructeur
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
        ?string $stripeRiskLevel = null
    ) {
        $this->userId = $userId;
        $this->orderSource = $orderSource;
        $this->paymentMethod = $paymentMethod;
        $this->addressId = $addressId;
        $this->carrierId = $carrierId;
        $this->typeOrder = $typeOrder;
        $this->items = $items;
        $this->priceShipping = $priceShipping;

        // 🟢 Infos Square
        $this->squarePaymentId = $squarePaymentId;
        $this->squareOrderId = $squareOrderId;
        $this->squareReceiptUrl = $squareReceiptUrl;
        $this->squareStatus = $squareStatus;
        $this->squareCardBrand = $squareCardBrand;
        $this->squareLast4 = $squareLast4;
        $this->squareRiskLevel = $squareRiskLevel;
        $this->stripePaymentId = $stripePaymentId;
        $this->stripeReceiptUrl = $stripeReceiptUrl;
        $this->stripeStatus = $stripeStatus;
        $this->stripeCardBrand = $stripeCardBrand;
        $this->stripeLast4 = $stripeLast4;
        $this->stripeRiskLevel = $stripeRiskLevel;
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
    public function getAddressId(): int
    {
        return $this->addressId;
    }

    // CORRECTION 3 : Le getter doit pouvoir retourner null
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

    // Implémentation de l'interface : retourne un int (compatible avec ?int)
    public function getPaymentMethod(): int
    {
        return $this->paymentMethod;
    }

    public function getPriceShipping(): ?float
    {
        return $this->priceShipping;
    }

    // 🔑 Getters pour les infos de paiement Square
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
}

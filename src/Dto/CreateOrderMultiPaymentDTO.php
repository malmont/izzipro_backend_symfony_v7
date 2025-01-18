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

    // 🔑 Infos de paiement Square
    private ?string $squarePaymentId;
    private ?string $squareOrderId;
    private ?string $squareReceiptUrl;
    private ?string $squareStatus;
    private ?string $squareCardBrand;
    private ?string $squareLast4;
    private ?string $squareRiskLevel;

    public function __construct(
        int $userId,
        int $orderSource,
        array $paymentMethods,
        int $addressId,
        int $carrierId,
        int $typeOrder,
        array $items,
        ?string $squarePaymentId = null,
        ?string $squareOrderId = null,
        ?string $squareReceiptUrl = null,
        ?string $squareStatus = null,
        ?string $squareCardBrand = null,
        ?string $squareLast4 = null,
        ?string $squareRiskLevel = null
    ) {
        $this->userId = $userId;
        $this->orderSource = $orderSource;
        $this->paymentMethods = $paymentMethods;
        $this->addressId = $addressId;
        $this->carrierId = $carrierId;
        $this->typeOrder = $typeOrder;
        $this->items = $items;

        // 🟢 Initialisation des données de paiement Square
        $this->squarePaymentId = $squarePaymentId;
        $this->squareOrderId = $squareOrderId;
        $this->squareReceiptUrl = $squareReceiptUrl;
        $this->squareStatus = $squareStatus;
        $this->squareCardBrand = $squareCardBrand;
        $this->squareLast4 = $squareLast4;
        $this->squareRiskLevel = $squareRiskLevel;
    }

    // ✅ Getters existants
    public function getUserId(): int { return $this->userId; }
    public function getOrderSource(): int { return $this->orderSource; }
    public function getPaymentMethods(): array { return $this->paymentMethods; }
    public function getAddressId(): int { return $this->addressId; }
    public function getCarrierId(): int { return $this->carrierId; }
    public function getTypeOrder(): int { return $this->typeOrder; }
    public function getItems(): array { return $this->items; }

    // ✅ Getters pour les données de paiement Square
    public function getSquarePaymentId(): ?string { return $this->squarePaymentId; }
    public function getSquareOrderId(): ?string { return $this->squareOrderId; }
    public function getSquareReceiptUrl(): ?string { return $this->squareReceiptUrl; }
    public function getSquareStatus(): ?string { return $this->squareStatus; }
    public function getSquareCardBrand(): ?string { return $this->squareCardBrand; }
    public function getSquareLast4(): ?string { return $this->squareLast4; }
    public function getSquareRiskLevel(): ?string { return $this->squareRiskLevel; }
}

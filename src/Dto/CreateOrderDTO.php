<?php
namespace App\Dto;
use App\Dto\ICreateOrderDTO; 

class CreateOrderDTO implements ICreateOrderDTO
{
    private int $userId;
    private int $orderSource;
    private int $paymentMethod;
    private int $addressId;
    private int $carrierId;
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

    public function __construct(
        int $userId,
        int $orderSource,
        int $paymentMethod,
        int $addressId,
        int $carrierId,
        int $typeOrder,
        array $items,
        ?float $priceShipping = null,
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
    }

    // ✅ Getters existants
    public function getUserId(): int { return $this->userId; }
    public function getOrderSource(): int { return $this->orderSource; }
    public function getAddressId(): int { return $this->addressId; }
    public function getCarrierId(): int { return $this->carrierId; }
    public function getTypeOrder(): int { return $this->typeOrder; }
    public function getItems(): array { return $this->items; }
    public function getPaymentMethod(): int { return $this->paymentMethod; }
    public function getPriceShipping(): ?float { return $this->priceShipping; }

    // 🔑 Getters pour les infos de paiement Square
    public function getSquarePaymentId(): ?string { return $this->squarePaymentId; }
    public function getSquareOrderId(): ?string { return $this->squareOrderId; }
    public function getSquareReceiptUrl(): ?string { return $this->squareReceiptUrl; }
    public function getSquareStatus(): ?string { return $this->squareStatus; }
    public function getSquareCardBrand(): ?string { return $this->squareCardBrand; }
    public function getSquareLast4(): ?string { return $this->squareLast4; }
    public function getSquareRiskLevel(): ?string { return $this->squareRiskLevel; }
}
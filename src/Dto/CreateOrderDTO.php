<?php
namespace App\Dto;

class CreateOrderDTO
{
    private $userId;
    private $orderSource;
    private $paymentMethod;
    private $addressId;
    private $carrierId;
    private $typeOrder;
    private $items;

    public function __construct(
        int $userId,
        int $orderSource,
        int $paymentMethod,
        int $addressId,
        int $carrierId,
        int $typeOrder,
        array $items
    ) {
        $this->userId = $userId;
        $this->orderSource = $orderSource;
        $this->paymentMethod = $paymentMethod;
        $this->addressId = $addressId;
        $this->carrierId = $carrierId;
        $this->typeOrder = $typeOrder;
        $this->items = $items;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getOrderSource(): int
    {
        return $this->orderSource;
    }

    public function getPaymentMethod(): int
    {
        return $this->paymentMethod;
    }

    public function getAddressId(): int
    {
        return $this->addressId;
    }

    public function getCarrierId(): int
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
}

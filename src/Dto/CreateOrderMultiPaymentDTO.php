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

    public function __construct(
        int $userId,
        int $orderSource,
        array $paymentMethods,
        int $addressId,
        int $carrierId,
        int $typeOrder,
        array $items
    ) {
        $this->userId = $userId;
        $this->orderSource = $orderSource;
        $this->paymentMethods = $paymentMethods;
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

    public function getPaymentMethods(): array
    {
        return $this->paymentMethods;
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

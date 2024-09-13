<?php

namespace App\Dto;

class OrderDTO
{
    private int $id;
    private string $reference;
    private float $totalAmount;
    private string $orderDate;
    private ?int $userId;
    private ?int $shippingAdress;
    private ?string $orderSource;
    private array $orderItems;

    public function __construct(
        int $id,
        string $reference,
        float $totalAmount,
        string $orderDate,
        ?int $userId,
        ?int $shippingAdress,
        ?string $orderSource,
        array $orderItems
    ) {
        $this->id = $id;
        $this->reference = $reference;
        $this->totalAmount = $totalAmount;
        $this->orderDate = $orderDate;
        $this->userId = $userId;
        $this->shippingAdress = $shippingAdress;
        $this->orderSource = $orderSource;
        $this->orderItems = $orderItems;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'totalAmount' => $this->totalAmount,
            'orderDate' => $this->orderDate,
            'userId' => $this->userId,
            'shippingAdress' => $this->shippingAdress,
            'orderSource' => $this->orderSource,
            'orderItems' => array_map(fn($item) => $item->toArray(), $this->orderItems),
        ];
    }
}

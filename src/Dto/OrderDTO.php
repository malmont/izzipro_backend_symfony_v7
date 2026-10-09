<?php

namespace App\Dto;
use App\Dto\AdressOutputDTO;

class OrderDTO
{
    public int $id;
    public string $reference;
    public float $totalAmount;
    public ?float $subTotal;
    public ?float $totalTax;
    public ?float $shippingCost;
    public string $orderDate;
    public ?int $userId;
    public ?AdressOutputDTO $shippingAdress; 
    public ?string $orderSource;
    public ?string $status;
    public array $orderItems;
    /** Boutique réglable (08/10/2026) : devise du site, identifiant du statut, transporteur */
    public string $currency = 'CAD';
    public ?int $statusId = null;
    public ?array $carrier = null;

    public function __construct(
        int $id,
        string $reference,
        float $totalAmount,
        ?float $subTotal,
        ?float $totalTax,
        ?float $shippingCost,
        string $orderDate,
        ?int $userId,
        ?AdressOutputDTO $shippingAdress, 
        ?string $orderSource,
        ?string $status,
        array $orderItems
    ) {
        $this->id = $id;
        $this->reference = $reference;
        $this->totalAmount = $totalAmount;
        $this->subTotal = $subTotal;
        $this->totalTax = $totalTax;
        $this->shippingCost = $shippingCost;
        $this->orderDate = $orderDate;
        $this->userId = $userId;
        $this->shippingAdress = $shippingAdress;
        $this->orderSource = $orderSource;
        $this->status = $status;
        $this->orderItems = $orderItems;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            // cents entiers (les colonnes sont décimales)
            'totalAmount' => (int) round($this->totalAmount),
            'subTotal' => $this->subTotal === null ? null : (int) round($this->subTotal),
            'priceTax' => $this->totalTax === null ? null : (int) round($this->totalTax),
            'priceShipping' => $this->shippingCost === null ? null : (int) round($this->shippingCost),
            'currency' => $this->currency,
            'statusId' => $this->statusId,
            'carrier' => $this->carrier,
            'orderDate' => $this->orderDate,
            'userId' => $this->userId,
            'shippingAdress' => $this->shippingAdress,
            'orderSource' => $this->orderSource,
            'status' => $this->status,
            'orderItems' => array_map(fn($item) => $item->toArray(), $this->orderItems),
        ];
    }
}
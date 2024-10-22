<?php

namespace App\Dto;

use App\Entity\OrderItems;
use App\Entity\Product;

class OrderItemDTO
{
    public int $id;
    public int $quantity;
    public float $unitPrice;
    public float $totalPrice;
    public int $productId;
    public string $productVariantName;
    public string $productVariantSize;
    public string $productVariantColor;
    public ?string $productImage;

    public function __construct(OrderItems $orderItem, string $host)
    {
        $this->id = $orderItem->getId();
        $this->quantity = $orderItem->getQuantity();
        $this->unitPrice = $orderItem->getUnitPrice();
        $this->totalPrice = $orderItem->getTotalPrice();

        $product = $orderItem->getProductVariant()->getProduct();
        $this->productId = $product->getId();
        $this->productVariantName = $product->getName();

        $variant = $orderItem->getProductVariant();
        $this->productVariantColor = $variant->getColor() ? $variant->getColor()->getName() : 'Inconnu';
        $this->productVariantSize = $variant->getSize() ? $variant->getSize()->getName() : 'Inconnu';
        // Gestion de l'image avec le chemin complet
        $this->productImage = $product->getImage() 
            ? $host . '/assets/uploads/products/' . $product->getImage() 
            : null;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'quantity' => $this->quantity,
            'unitPrice' => $this->unitPrice,
            'totalPrice' => $this->totalPrice,
            'productId' => $this->productId,
            'productVariantName' => $this->productVariantName,
            'productVariantColor' => $this->productVariantColor,
            'productVariantSize' => $this->productVariantSize,
            'productImage' => $this->productImage,
        ];
    }
}

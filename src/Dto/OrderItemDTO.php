<?php

namespace App\Dto;

use App\Entity\OrderItems;
use App\Entity\Booking;
use App\Entity\ProductOptionValue; 
use DateTimeInterface;

class OrderItemDTO
{
    public int $id;
    public int $quantity;
    public float $unitPrice;
    public float $totalPrice;
    public int $productId;
    public string $productVariantName;
    
    public ?string $productVariantSize = null;
    public ?string $productVariantColor = null;
    
    public ?string $productImage;
    public ?string $productSaleUnit = null;
    
    public ?array $booking = null;

    public array $options = [];

    public function __construct(OrderItems $orderItem, string $host)
    {
        $this->id = $orderItem->getId();
        $this->quantity = $orderItem->getQuantity();
        $this->unitPrice = $orderItem->getUnitPrice();
        $this->totalPrice = $orderItem->getTotalPrice();

        $variant = $orderItem->getProductVariant();
        $product = $variant->getProduct();
        
        $this->productId = $product->getId();
        $this->productVariantName = $product->getName();
        
        // Use the snapshot stored in the entity first, fall back to product if empty (for older orders)
        $this->productSaleUnit = $orderItem->getSaleUnit() 
            ?? ($product->getSaleUnit() ? $product->getSaleUnit()->getName() : null);


        $this->productVariantColor = $variant->getColor() ? $variant->getColor()->getName() : null;
        $this->productVariantSize = $variant->getSize() ? $variant->getSize()->getName() : null;
        

        foreach ($variant->getOptionValues() as $optionValue) {
            $parentOption = $optionValue->getProductOption();
            
            if ($parentOption) {
                $optionName = $parentOption->getName(); 
                $valueName = $optionValue->getValue();

                $this->options[$optionName] = $valueName;
            }
        }

        // --- 3. GESTION IMAGE ---
        $imagePath = $product->getImage();
        if ($imagePath) {
            $this->productImage = str_starts_with($imagePath, 'http')
                ? $imagePath
                : rtrim($host, '/') . '/assets/uploads/products/' . $imagePath;
        } else {
            $this->productImage = null;
        }

        // --- 4. GESTION BOOKING ---
        $bookingEntity = $orderItem->getBooking(); 

        if ($bookingEntity) {
            $this->booking = [
                'start' => $bookingEntity->getStartAt()->format(DateTimeInterface::ATOM),
                'end'   => $bookingEntity->getEndAt()->format(DateTimeInterface::ATOM),
                // On peut ajouter le status si besoin
                'status'=> $bookingEntity->getStatus(), 
            ];
        }
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
            'productSaleUnit' => $this->productSaleUnit,
            'productVariantColor' => $this->productVariantColor,
            'productVariantSize' => $this->productVariantSize,
            'productImage' => $this->productImage,
            'booking' => $this->booking,
            'options' => $this->options, // ✅ On envoie le tableau d'options au front
        ];
    }
}
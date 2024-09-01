<?php

namespace App\Entity;

use App\Repository\InventoryMovementsRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InventoryMovementsRepository::class)]
class InventoryMovements
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'inventoryMovements')]
    private ?ProductVariant $productVariant = null;

    #[ORM\ManyToOne(inversedBy: 'inventoryMovements')]
    private ?MovementType $movementType = null;

    #[ORM\Column]
    private ?int $quantity = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private ?\DateTimeInterface $movementDate = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProductVariant(): ?ProductVariant
    {
        return $this->productVariant;
    }

    public function setProductVariant(?ProductVariant $productVariant): static
    {
        $this->productVariant = $productVariant;

        return $this;
    }

    public function getMovementType(): ?MovementType
    {
        return $this->movementType;
    }

    public function setMovementType(?MovementType $movementType): static
    {
        $this->movementType = $movementType;

        return $this;
    }

    public function getQuantity(): ?int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): static
    {
        $this->quantity = $quantity;

        return $this;
    }

    public function getMovementDate(): ?\DateTimeInterface
    {
        return $this->movementDate;
    }

    public function setMovementDate(\DateTimeInterface $movementDate): static
    {
        $this->movementDate = $movementDate;

        return $this;
    }
}

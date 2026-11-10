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

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $movementDate = null;

    #[ORM\Column(nullable: true)]
    private ?int $stockBeforeMovement = null;

    #[ORM\Column(nullable: true)]
    private ?float $stockAfterMovement = null;

    /** Ligne d'achat réceptionnée (lot R1 de la gestion, 10/11/2026) ; NULL pour les ventes, retours et ajustements de la caisse */
    #[ORM\ManyToOne(targetEntity: CommandeLine::class)]
    #[ORM\JoinColumn(name: 'commande_line_id', nullable: true, onDelete: 'SET NULL')]
    private ?CommandeLine $commandeLine = null;

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

    public function getStockBeforeMovement(): ?int
    {
        return $this->stockBeforeMovement;
    }

    public function setStockBeforeMovement(?int $stockBeforeMovement): static
    {
        $this->stockBeforeMovement = $stockBeforeMovement;

        return $this;
    }

    public function getStockAfterMovement(): ?float
    {
        return $this->stockAfterMovement;
    }

    public function getCommandeLine(): ?CommandeLine
    {
        return $this->commandeLine;
    }

    public function setCommandeLine(?CommandeLine $line): static
    {
        $this->commandeLine = $line;

        return $this;
    }

    public function setStockAfterMovement(?float $stockAfterMovement): static
    {
        $this->stockAfterMovement = $stockAfterMovement;

        return $this;
    }
}

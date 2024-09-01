<?php

namespace App\Entity;

use App\Repository\MovementTypeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MovementTypeRepository::class)]
class MovementType
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description = null;

    /**
     * @var Collection<int, InventoryMovements>
     */
    #[ORM\OneToMany(mappedBy: 'movementType', targetEntity: InventoryMovements::class)]
    private Collection $inventoryMovements;

    public function __construct()
    {
        $this->inventoryMovements = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    /**
     * @return Collection<int, InventoryMovements>
     */
    public function getInventoryMovements(): Collection
    {
        return $this->inventoryMovements;
    }

    public function addInventoryMovement(InventoryMovements $inventoryMovement): static
    {
        if (!$this->inventoryMovements->contains($inventoryMovement)) {
            $this->inventoryMovements->add($inventoryMovement);
            $inventoryMovement->setMovementType($this);
        }

        return $this;
    }

    public function removeInventoryMovement(InventoryMovements $inventoryMovement): static
    {
        if ($this->inventoryMovements->removeElement($inventoryMovement)) {
            // set the owning side to null (unless already changed)
            if ($inventoryMovement->getMovementType() === $this) {
                $inventoryMovement->setMovementType(null);
            }
        }

        return $this;
    }

    public function __toString(): string
    {
        return $this->name ?? 'N/A'; // Retourne le nom du mouvement ou 'N/A' s'il est null
    }
}

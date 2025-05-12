<?php

namespace App\Entity;

use App\Repository\ShippingClassRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ShippingClassRepository::class)]
class ShippingClass
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private ?string $name = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /**
     * @var Collection<int, ProductShipping>
     */
    #[ORM\OneToMany(mappedBy: 'shippingClassEntity', targetEntity: ProductShipping::class)]
    private Collection $productShippings;

    public function __construct()
    {
        $this->productShippings = new ArrayCollection();
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
     * @return Collection<int, ProductShipping>
     */
    public function getProductShippings(): Collection
    {
        return $this->productShippings;
    }

    public function addProductShipping(ProductShipping $productShipping): static
    {
        if (!$this->productShippings->contains($productShipping)) {
            $this->productShippings->add($productShipping);
            $productShipping->setShippingClassEntity($this);
        }

        return $this;
    }

    public function removeProductShipping(ProductShipping $productShipping): static
    {
        if ($this->productShippings->removeElement($productShipping)) {
            // set the owning side to null (unless already changed)
            if ($productShipping->getShippingClassEntity() === $this) {
                $productShipping->setShippingClassEntity(null);
            }
        }

        return $this;
    }

    public function __toString(): string
    {
        return (string) $this->name;
    }
}

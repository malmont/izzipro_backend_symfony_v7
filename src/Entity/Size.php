<?php

namespace App\Entity;

use App\Repository\SizeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SizeRepository::class)]
class Size
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    /**
     * @var Collection<int, ProductVariant>
     */
    #[ORM\OneToMany(mappedBy: 'size', targetEntity: ProductVariant::class)]
    private Collection $variantSize;

    public function __construct()
    {
        $this->variantSize = new ArrayCollection();
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

    /**
     * @return Collection<int, ProductVariant>
     */
    public function getVariantSize(): Collection
    {
        return $this->variantSize;
    }

    public function addVariantSize(ProductVariant $variantSize): static
    {
        if (!$this->variantSize->contains($variantSize)) {
            $this->variantSize->add($variantSize);
            $variantSize->setSize($this);
        }

        return $this;
    }

    public function removeVariantSize(ProductVariant $variantSize): static
    {
        if ($this->variantSize->removeElement($variantSize)) {
            // set the owning side to null (unless already changed)
            if ($variantSize->getSize() === $this) {
                $variantSize->setSize(null);
            }
        }

        return $this;
    }
    public function __toString(): string
    {
        return $this->name ?: 'N/A';
    }

}

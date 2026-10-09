<?php

namespace App\Entity;

use App\Repository\ProductCustomizationImageRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[\App\Validator\UniqueCustomizationCombination]
#[ORM\Entity(repositoryClass: ProductCustomizationImageRepository::class)]
class ProductCustomizationImage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $imagePath = null;

    #[ORM\Column]
    private ?int $numberOfPieces = null;

    #[ORM\ManyToOne(inversedBy: 'productCustomizationImages', cascade: ['persist'])] 
    private ?ProductVariant $productVariant = null;

    /**
     * @var Collection<int, ProductOptionValue>
     */
    #[ORM\ManyToMany(targetEntity: ProductOptionValue::class, inversedBy: 'productCustomizationImages')]
    private Collection $optionValues;

    public function __construct()
    {
        $this->optionValues = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getImagePath(): ?string
    {
        return $this->imagePath;
    }

    public function setImagePath(string $imagePath): static
    {
        $this->imagePath = $imagePath;

        return $this;
    }

    public function getNumberOfPieces(): ?int
    {
        return $this->numberOfPieces;
    }

    public function setNumberOfPieces(int $numberOfPieces): static
    {
        $this->numberOfPieces = $numberOfPieces;

        return $this;
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

    /**
     * @return Collection<int, ProductOptionValue>
     */
    public function getOptionValues(): Collection
    {
        return $this->optionValues;
    }

    public function addOptionValue(ProductOptionValue $optionValue): static
    {
        if (!$this->optionValues->contains($optionValue)) {
            $this->optionValues->add($optionValue);
        }

        return $this;
    }

    public function removeOptionValue(ProductOptionValue $optionValue): static
    {
        $this->optionValues->removeElement($optionValue);

        return $this;
    }

    public function setOptionValues(Collection $optionValues): static
    {
        $this->optionValues = $optionValues;
        return $this;
    }

    public function __toString(): string
    {
        return 'Image #' . ($this->getId() ?? 'Nouvelle');
    }

    /**
     * Supplément de la combinaison en cents : somme des suppléments de ses options (stockés en dollars sur chaque
     * valeur d'option). Même calcul pour la configuration affichée et pour le devis du panier.
     */
    public function priceDeltaCents(): int
    {
        $delta = 0.0;
        foreach ($this->getOptionValues() as $value) {
            $delta += (float) ($value->getPriceDelta() ?? 0.0);
        }

        return (int) round($delta * 100);
    }
}

<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use App\Repository\ProductVariantRepository;
use App\Services\BarcodeService\CatalogBarcode;

#[ORM\Entity(repositoryClass: ProductVariantRepository::class)]
#[ORM\HasLifecycleCallbacks]
// Lot CB-1 (15/11/2026, `docs/codes-barres.md`) : le code-barres ne désigne rien d'autre sur le site (refus sous le champ, avant la base)
#[\App\Validator\CatalogBarcodeFree]
class ProductVariant
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    private ?Color $color = null;

    #[ORM\ManyToOne(inversedBy: 'variantSize')]
    private ?Size $size = null;

    #[ORM\ManyToOne(inversedBy: 'variants')]
    private ?Product $product = null;

    #[ORM\Column]
    private int $stockQuantity = 0;

    /** Prix de cette variante en cents, null = prix du produit (boutique réglable, 08/10/2026) */
    #[ORM\Column(nullable: true)]
    private ?int $price = null;

    // Code-barres de la variante (C5 hors ligne, 25/10/2026) : EAN/UPC propre à la variante, saisissable dans EasyAdmin ;
    // le POS cherche la variante puis le produit (code-barres produit existant). Lot CB-1 (15/11/2026, `docs/codes-barres.md`) : 64
    // caractères, forme canonique, et un code ne désigne qu'UNE chose sur le site (article ou variante), garanti par la base
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $barcode = null;

    /**
     * @var Collection<int, OrderItems>
     */
    #[ORM\OneToMany(mappedBy: 'productVariant', targetEntity: OrderItems::class)]
    private Collection $orderItems;

    /**
     * @var Collection<int, InventoryMovements>
     */
    #[ORM\OneToMany(mappedBy: 'productVariant', targetEntity: InventoryMovements::class)]
    private Collection $inventoryMovements;

    /**
     * @var Collection<int, ProductOptionValue>
     */
    #[ORM\ManyToMany(targetEntity: ProductOptionValue::class, inversedBy: 'productVariants')]
    private Collection $optionValues;

    /**
     * @var Collection<int, ProductCustomizationImage>
     */
    // Combinaisons validées avec la variante (unicité par ensemble d'options, 09/10/2026)
    #[\Symfony\Component\Validator\Constraints\Valid]
    #[ORM\OneToMany(
        mappedBy: 'productVariant', 
        targetEntity: ProductCustomizationImage::class, 
        cascade: ['persist', 'remove'], 
        orphanRemoval: true 
    )]
    private Collection $productCustomizationImages;

    /**
     * Promotions de la variante (lot VAR, 11/11/2026) : lues par VariantPriceResolver seulement si l'interrupteur du site est allumé
     *
     * @var Collection<int, VariantPromotion>
     */
    #[ORM\OneToMany(mappedBy: 'productVariant', targetEntity: VariantPromotion::class)]
    #[ORM\OrderBy(['startsAt' => 'ASC', 'id' => 'ASC'])]
    private Collection $variantPromotions;

    public function __construct()
    {
        $this->orderItems = new ArrayCollection();
        $this->inventoryMovements = new ArrayCollection();
        $this->optionValues = new ArrayCollection();
        $this->productCustomizationImages = new ArrayCollection();
        $this->variantPromotions = new ArrayCollection();
        $this->stockQuantity = 0;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getColor(): ?Color
    {
        return $this->color;
    }

    public function setColor(?Color $color): static
    {
        $this->color = $color;

        return $this;
    }

    public function getSize(): ?Size
    {
        return $this->size;
    }

    public function setSize(?Size $size): static
    {
        $this->size = $size;

        return $this;
    }
    public function getProductName(): ?string
    {
        return $this->product ? $this->product->getName() : null;
    }

    public function getProduct(): ?Product
    {
        return $this->product;
    }

    public function setProduct(?Product $product): static
    {
        $this->product = $product;

        return $this;
    }

    public function getPrice(): ?int
    {
        return $this->price;
    }

    public function setPrice(?int $price): static
    {
        $this->price = $price;

        return $this;
    }

    public function getStockQuantity(): int
    {
        return $this->stockQuantity;
    }

    public function setStockQuantity(int $stockQuantity): static
    {
        $this->stockQuantity = $stockQuantity;

        return $this;
    }

    #[ORM\PostPersist]
    #[ORM\PostUpdate]
    public function updateProductQuantityPostPersistAndUpdate(PostPersistEventArgs|PostUpdateEventArgs $args): void
    {
        // même forme que la suppression (lot ART-SUPPR-2) : jamais de « persist » d'un article qui n'est pas géré (il serait réenregistré
        // sous un nouvel identifiant) ; la quantité n'est recalculée que pour un article vivant
        $entityManager = $args->getEntityManager();
        if ($this->product === null || $entityManager->getUnitOfWork()->getEntityState($this->product) !== \Doctrine\ORM\UnitOfWork::STATE_MANAGED) {
            return;
        }
        $this->product->updateQuantity();
        $entityManager->flush();
    }

    /**
     * Variante supprimée : la quantité de son article est recalculée, seulement si l'article VIT encore. Quand la variante part avec
     * son article, celui-ci n'est plus géré (il est supprimé lui aussi) : le « persister » ici le réenregistrait sous un nouvel
     * identifiant, avec ses variantes, et l'article supprimé revenait (défaut corrigé le 15/11/2026, lot ART-SUPPR-2).
     */
    #[ORM\PostRemove]
    public function updateProductQuantityPostRemove(PostRemoveEventArgs $args): void
    {
        $entityManager = $args->getEntityManager();
        if ($this->product === null || $entityManager->getUnitOfWork()->getEntityState($this->product) !== \Doctrine\ORM\UnitOfWork::STATE_MANAGED) {
            return;
        }
        $this->product->updateQuantity();
        $entityManager->flush();
    }

    /**
     * @return Collection<int, OrderItems>
     */
    public function getOrderItems(): Collection
    {
        return $this->orderItems;
    }

    public function addOrderItem(OrderItems $orderItem): static
    {
        if (!$this->orderItems->contains($orderItem)) {
            $this->orderItems->add($orderItem);
            $orderItem->setProductVariant($this);
        }

        return $this;
    }

    public function removeOrderItem(OrderItems $orderItem): static
    {
        if ($this->orderItems->removeElement($orderItem)) {
            // set the owning side to null (unless already changed)
            if ($orderItem->getProductVariant() === $this) {
                $orderItem->setProductVariant(null);
            }
        }

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
            $inventoryMovement->setProductVariant($this);
        }

        return $this;
    }


    public function removeInventoryMovement(InventoryMovements $inventoryMovement): static
    {
        if ($this->inventoryMovements->removeElement($inventoryMovement)) {
            // set the owning side to null (unless already changed)
            if ($inventoryMovement->getProductVariant() === $this) {
                $inventoryMovement->setProductVariant(null);
            }
        }

        return $this;
    }

    public function __toString(): string
    {
        return (string)  ' id: ' . $this->id . ' - ' . ' qt: ' . $this->stockQuantity . ' - ' . ($this->product ? $this->product->getName() . ' - ' . $this->color . ' taille ' . $this->size  : 'Sans produit associé');
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

    /**
     * @return Collection<int, ProductCustomizationImage>
     */
    public function getProductCustomizationImages(): Collection
    {
        return $this->productCustomizationImages;
    }

    public function addProductCustomizationImage(ProductCustomizationImage $productCustomizationImage): static
    {
        if (!$this->productCustomizationImages->contains($productCustomizationImage)) {
            $this->productCustomizationImages->add($productCustomizationImage);
            $productCustomizationImage->setProductVariant($this);
        }

        return $this;
    }

    public function removeProductCustomizationImage(ProductCustomizationImage $productCustomizationImage): static
    {
        if ($this->productCustomizationImages->removeElement($productCustomizationImage)) {
            if ($productCustomizationImage->getProductVariant() === $this) {
                $productCustomizationImage->setProductVariant(null);
            }
        }

        return $this;
    }
    public function getBarcode(): ?string { return $this->barcode; }
    /** Forme canonique dès l'écriture ; plus de coupe silencieuse : `CatalogBarcodeService` refuse un code trop long, la base aussi */
    public function setBarcode(?string $barcode): static { $this->barcode = CatalogBarcode::normalize($barcode); return $this; }

    /** @return Collection<int, VariantPromotion> */
    public function getVariantPromotions(): Collection
    {
        return $this->variantPromotions;
    }
}

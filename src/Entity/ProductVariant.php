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

#[ORM\Entity(repositoryClass: ProductVariantRepository::class)]
#[ORM\HasLifecycleCallbacks]
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
    #[ORM\OneToMany(
        mappedBy: 'productVariant', 
        targetEntity: ProductCustomizationImage::class, 
        cascade: ['persist', 'remove'], 
        orphanRemoval: true 
    )]
    private Collection $productCustomizationImages;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $gemsuiteVariantId = null;

    public function __construct()
    {
        $this->orderItems = new ArrayCollection();
        $this->inventoryMovements = new ArrayCollection();
        $this->optionValues = new ArrayCollection();
        $this->productCustomizationImages = new ArrayCollection();
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
        if ($this->product) {
            $this->product->updateQuantity();
            $entityManager = $args->getEntityManager();
            $entityManager->persist($this->product);
            $entityManager->flush();
        }
    }

    #[ORM\PostRemove]
    public function updateProductQuantityPostRemove(PostRemoveEventArgs $args): void
    {
        if ($this->product) {
            $this->product->updateQuantity();
            $entityManager = $args->getEntityManager();
            $entityManager->persist($this->product);
            $entityManager->flush();
        }
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

    public function getGemsuiteVariantId(): ?string
    {
        return $this->gemsuiteVariantId;
    }

    public function setGemsuiteVariantId(?string $gemsuiteVariantId): static
    {
        $this->gemsuiteVariantId = $gemsuiteVariantId;

        return $this;
    }
}

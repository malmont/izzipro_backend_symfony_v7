<?php

namespace App\Entity;

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
    private ?int $stockQuantity = null;

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

    public function getProduct(): ?Product
    {
        return $this->product;
    }

    public function setProduct(?Product $product): static
    {
        $this->product = $product;

        return $this;
    }

    public function getStockQuantity(): ?int
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
}

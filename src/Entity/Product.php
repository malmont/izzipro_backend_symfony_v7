<?php
namespace App\Entity;

use DateTime;
use App\Entity\Categories;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Repository\ProductRepository;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\ArrayCollection;

#[ORM\Entity(repositoryClass: ProductRepository::class)]
#[ApiResource]
class Product
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $description = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $moreinformations = null;

    #[ORM\Column]
    private ?float $price = null;

    #[ORM\Column]
    private ?bool $isbestseller = false;

    #[ORM\Column(nullable: true)]
    private ?bool $isnewarrival = false;

    #[ORM\Column(nullable: true)]
    private ?bool $isfeatured = false;

    #[ORM\Column(nullable: true)]
    private ?bool $isspecialoffer = false;

    #[ORM\Column(length: 255)]
    private ?string $image = null;

    #[ORM\ManyToMany(targetEntity: Categories::class, inversedBy: 'products')]
    private Collection $category;

    #[ORM\OneToMany(mappedBy: 'product', targetEntity: RelatedProduct::class)]
    private Collection $relatedProducts;

    #[ORM\OneToMany(mappedBy: 'productReviews', targetEntity: ReviewsProduct::class)]
    private Collection $reviewsProducts;

    #[ORM\Column]
    private ?int $quantity = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $tags = null;

    #[ORM\Column(length: 255)]
    private ?string $slug = null;

    #[ORM\Column(nullable: true)]
    private ?float $purchasePrice = null;

    #[ORM\Column(nullable: true)]
    private ?float $coefficientMultiplier = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $barcode = null;

    #[ORM\ManyToOne(inversedBy: 'products')]
    private ?Style $style = null;

    #[ORM\OneToMany(mappedBy: 'product', targetEntity: ProductVariant::class)]
    private Collection $variants;

    #[ORM\ManyToOne(inversedBy: 'products')]
    #[ORM\JoinColumn(nullable: true)]
    private ?Commande $commande = null;

    public function __construct()
    {
        $this->category = new ArrayCollection();
        $this->relatedProducts = new ArrayCollection();
        $this->reviewsProducts = new ArrayCollection();
        $this->createdAt = new DateTimeImmutable();
        $this->variants = new ArrayCollection();
        $this->updateQuantity(); 
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function getMoreinformations(): ?string
    {
        return $this->moreinformations;
    }

    public function setMoreinformations(?string $moreinformations): self
    {
        $this->moreinformations = $moreinformations;

        return $this;
    }

    public function getPrice(): ?float
    {
        return $this->price;
    }

    public function setPrice(float $price): self
    {
        $this->price = $price;

        return $this;
    }
    public function setPurchasePrice(?float $purchasePrice): static
    {
        $this->purchasePrice = $purchasePrice;
        $this->updatePrice(); // Recalcule le prix à chaque fois que le prix d'achat est modifié

        return $this;
    }

    public function setCoefficientMultiplier(?float $coefficientMultiplier): static
    {
        $this->coefficientMultiplier = $coefficientMultiplier;
        $this->updatePrice(); // Recalcule le prix à chaque fois que le coefficient est modifié

        return $this;
    }

    private function updatePrice(): void
    {
        if ($this->purchasePrice !== null && $this->coefficientMultiplier !== null) {
            $this->price = $this->purchasePrice * $this->coefficientMultiplier;
        }
    }

    public function isIsbestseller(): ?bool
    {
        return $this->isbestseller;
    }

    public function setIsbestseller(bool $isbestseller): self
    {
        $this->isbestseller = $isbestseller;

        return $this;
    }

    public function isIsnewarrival(): ?bool
    {
        return $this->isnewarrival;
    }

    public function setIsnewarrival(?bool $isnewarrival): self
    {
        $this->isnewarrival = $isnewarrival;

        return $this;
    }

    public function isIsfeatured(): ?bool
    {
        return $this->isfeatured;
    }

    public function setIsfeatured(?bool $isfeatured): self
    {
        $this->isfeatured = $isfeatured;

        return $this;
    }

    public function isIsspecialoffer(): ?bool
    {
        return $this->isspecialoffer;
    }

    public function setIsspecialoffer(?bool $isspecialoffer): self
    {
        $this->isspecialoffer = $isspecialoffer;

        return $this;
    }

    public function getImage(): ?string
    {
        return $this->image;
    }

    public function setImage(string $image): self
    {
        $this->image = $image;

        return $this;
    }

    public function getCategory(): Collection
    {
        return $this->category;
    }

    public function addCategory(Categories $category): self
    {
        if (!$this->category->contains($category)) {
            $this->category->add($category);
        }

        return $this;
    }

    public function removeCategory(Categories $category): self
    {
        $this->category->removeElement($category);

        return $this;
    }

    public function getRelatedProducts(): Collection
    {
        return $this->relatedProducts;
    }

    public function addRelatedProduct(RelatedProduct $relatedProduct): self
    {
        if (!$this->relatedProducts->contains($relatedProduct)) {
            $this->relatedProducts->add($relatedProduct);
            $relatedProduct->setProduct($this);
        }

        return $this;
    }

    public function removeRelatedProduct(RelatedProduct $relatedProduct): self
    {
        if ($this->relatedProducts->removeElement($relatedProduct)) {
            // set the owning side to null (unless already changed)
            if ($relatedProduct->getProduct() === $this) {
                $relatedProduct->setProduct(null);
            }
        }

        return $this;
    }

    public function getReviewsProducts(): Collection
    {
        return $this->reviewsProducts;
    }

    public function addReviewsProduct(ReviewsProduct $reviewsProduct): self
    {
        if (!$this->reviewsProducts->contains($reviewsProduct)) {
            $this->reviewsProducts->add($reviewsProduct);
            $reviewsProduct->setProductReviews($this);
        }

        return $this;
    }

    public function removeReviewsProduct(ReviewsProduct $reviewsProduct): self
    {
        if ($this->reviewsProducts->removeElement($reviewsProduct)) {
            // set the owning side to null (unless already changed)
            if ($reviewsProduct->getProductReviews() === $this) {
                $reviewsProduct->setProductReviews(null);
            }
        }

        return $this;
    }

    public function getQuantity(): ?int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): self
    {
        $this->quantity = $quantity;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getTags(): ?string
    {
        return $this->tags;
    }

    public function setTags(?string $tags): self
    {
        $this->tags = $tags;

        return $this;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): self
    {
        $this->slug = $slug;

        return $this;
    }

    public function getPurchasePrice(): ?float
    {
        return $this->purchasePrice;
    }

  
    public function getCoefficientMultiplier(): ?float
    {
        return $this->coefficientMultiplier;
    }

  

    public function getBarcode(): ?string
    {
        return $this->barcode;
    }

    public function setBarcode(?string $barcode): static
    {
        $this->barcode = $barcode;

        return $this;
    }

    public function getStyle(): ?Style
    {
        return $this->style;
    }

    public function setStyle(?Style $style): static
    {
        $this->style = $style;

        return $this;
    }

    public function updateQuantity(): void
    {
        $totalQuantity = 0;
        foreach ($this->variants as $variant) {
            $totalQuantity += $variant->getStockQuantity();
        }
        $this->quantity = $totalQuantity;
    }


    public function getVariants(): Collection
    {
        return $this->variants;
    }

    public function addVariant(ProductVariant $variant): static
    {
        if (!$this->variants->contains($variant)) {
            $this->variants->add($variant);
            $variant->setProduct($this);
            $this->updateQuantity(); // Recalcule la quantité totale
        }

        return $this;
    }

    public function removeVariant(ProductVariant $variant): static
    {
        if ($this->variants->removeElement($variant)) {
            if ($variant->getProduct() === $this) {
                $variant->setProduct(null);
            }
            $this->updateQuantity(); // Recalcule la quantité totale
        }

        return $this;
    }

    public function getCommande(): ?Commande
    {
        return $this->commande;
    }

    public function setCommande(?Commande $commande): static
    {
        $this->commande = $commande;

        return $this;
    }

    public function __toString(): string
    {
        return (string) $this->name;
    }
}

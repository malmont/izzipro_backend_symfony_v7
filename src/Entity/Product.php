<?php
namespace App\Entity;

use App\Enum\ProductMode;
use DateTime;
use App\Entity\Categories;
use Doctrine\DBAL\Types\Types;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use App\Repository\ProductRepository;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\ArrayCollection;
use App\Entity\TranslatableInterface;
use ApiPlatform\Metadata\ApiResource;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity(repositoryClass: ProductRepository::class)]
#[ORM\Index(name: 'idx_product_slug', fields: ['slug'])]
#[ORM\Index(name: 'idx_product_barcode', fields: ['barcode'])]
#[ORM\Index(name: 'idx_product_is_web', fields: ['isWeb'])]
#[ORM\Index(name: 'idx_product_is_pos', fields: ['isPos'])]
#[ORM\Index(name: 'idx_product_is_accessory', fields: ['isAccessory'])]
#[ApiResource]
class Product implements TranslatableInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)] 
    private ?string $description = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)] 
    private ?string $moreinformations =  '';

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

    #[ORM\Column(length: 255, nullable: true)] 
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

    #[ORM\OneToMany(mappedBy: 'product', targetEntity: ProductVariant::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $variants;

    #[ORM\ManyToOne(inversedBy: 'products')]
    #[ORM\JoinColumn(nullable: true)]
    private ?Commande $commande = null;

    #[ORM\Column(nullable: true)]
    private ?int $freezeQuantity = null;

    #[ORM\Column(nullable: true)]
    private ?bool $isAccessory = false;

    #[ORM\Column(nullable: true)]
    private ?bool $isWeb = null;

    #[ORM\Column(nullable: true)]
    private ?bool $isPos = null;

    #[ORM\OneToOne(mappedBy: 'product', cascade: ['persist', 'remove'])]
    private ?ProductShipping $productShipping = null;

    #[ORM\Column(nullable: true)]
    private ?int $gemsuiteProductId = null;

    #[ORM\Column(nullable: true)]
    private ?bool $gemsuiteWebDisplay = null;

    #[ORM\Column(nullable: true)]
    private ?bool $isLandingPage = null;

    #[ORM\ManyToOne(inversedBy: 'products')]
    private ?ProductType $productType = null;

    #[ORM\Column(nullable: true)]
    private ?array $specifications = null;

    /**
     * @var Collection<int, ProductTranslation>
     */
    #[ORM\OneToMany(
        mappedBy: 'product',
        targetEntity: ProductTranslation::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
        fetch: 'EXTRA_LAZY'
    )]
    private Collection $translations;

    #[ORM\Column(nullable: true)]
    #[Groups(['product:read'])]
    private ?float $specialPrice = null;
    
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $specialPriceFrom = null;
    
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $specialPriceTo = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    #[Groups(['product:read'])]
    private ?bool $isPreOrder = false;

    #[ORM\Column(type: 'string', length: 20, enumType: ProductMode::class, options: ['default' => 'retail'])]
    private ProductMode $mode = ProductMode::RETAIL;

    #[ORM\OneToOne(mappedBy: 'product', cascade: ['persist', 'remove'])]
    private ?BookingConfiguration $bookingConfiguration = null;

    /**
     * @var Collection<int, Booking>
     */
    #[ORM\OneToMany(mappedBy: 'product', targetEntity: Booking::class)]
    private Collection $bookings;

    #[ORM\ManyToOne(inversedBy: 'products')]
    #[Groups(['product:read'])]
    private ?SaleUnit $saleUnit = null;

    /**
     * @var Collection<int, ProductPicture>
     */
    #[ORM\OneToMany(mappedBy: 'product', targetEntity: ProductPicture::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[Groups(['product:read'])]
    private Collection $pictures;

    public function __construct()

    {
        $this->category = new ArrayCollection();
        $this->relatedProducts = new ArrayCollection();
        $this->reviewsProducts = new ArrayCollection();
        $this->createdAt = new DateTimeImmutable();
        $this->variants = new ArrayCollection();
        $this->updateQuantity();
        $this->translations = new ArrayCollection();
        $this->bookings = new ArrayCollection(); 
        $this->pictures = new ArrayCollection();
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

    public function setDescription(?string $description): self
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
        $this->updatePrice();

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

    public function setImage(?string $image): self
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
            $category->addProduct($this);
        }

        return $this;
    }

    public function removeCategory(Categories $category): self
    {
        if ($this->category->removeElement($category)) {
            $category->removeProduct($this);
        }

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

    public function getFreezeQuantity(): ?int
    {
        return $this->freezeQuantity;
    }

    public function setFreezeQuantity(?int $freezeQuantity): static
    {
        $this->freezeQuantity = $freezeQuantity;

        return $this;
    }

    public function isAccessory(): ?bool
    {
        return $this->isAccessory;
    }

    // Setter
    public function setIsAccessory(?bool $isAccessory): static
    {
        $this->isAccessory = $isAccessory;

        return $this;
    }

    public function isWeb(): ?bool
    {
        return $this->isWeb;
    }

    public function setIsWeb(?bool $isWeb): static
    {
        $this->isWeb = $isWeb;

        return $this;
    }

    public function isPos(): ?bool
    {
        return $this->isPos;
    }

    public function setIsPos(?bool $isPos): static
    {
        $this->isPos = $isPos;

        return $this;
    }

    public function getProductShipping(): ?ProductShipping
    {
        return $this->productShipping;
    }

    public function setProductShipping(?ProductShipping $productShipping): static
    {

        if ($productShipping === null && $this->productShipping !== null) {
            $this->productShipping->setProduct(null);
        }

        if ($productShipping !== null && $productShipping->getProduct() !== $this) {
            $productShipping->setProduct($this);
        }

        $this->productShipping = $productShipping;

        return $this;
    }

    public function getGemsuiteProductId(): ?int
    {
        return $this->gemsuiteProductId;
    }

    public function setGemsuiteProductId(?int $gemsuiteProductId): static
    {
        $this->gemsuiteProductId = $gemsuiteProductId;

        return $this;
    }

    public function isGemsuiteWebDisplay(): ?bool
    {
        return $this->gemsuiteWebDisplay;
    }

    public function setGemsuiteWebDisplay(?bool $gemsuiteWebDisplay): static
    {
        $this->gemsuiteWebDisplay = $gemsuiteWebDisplay;

        return $this;
    }

    public function getSaleUnit(): ?SaleUnit
    {
        return $this->saleUnit;
    }

    public function setSaleUnit(?SaleUnit $saleUnit): static
    {
        $this->saleUnit = $saleUnit;

        return $this;
    }


    public function isLandingPage(): ?bool
    {
        return $this->isLandingPage;
    }

    public function setIsLandingPage(?bool $isLandingPage): static
    {
        $this->isLandingPage = $isLandingPage;

        return $this;
    }

    public function getProductType(): ?ProductType
    {
        return $this->productType;
    }

    public function setProductType(?ProductType $productType): self
    {
        $this->productType = $productType;

        return $this;
    }

    public function getSpecifications(): ?array
    {
        return $this->specifications;
    }

    public function setSpecifications(?array $specifications): static
    {
        $this->specifications = $specifications;

        return $this;
    }

    /**
     * @return Collection<int, ProductTranslation>
     */
    public function getTranslations(): Collection
    {
        return $this->translations;
    }

    public function addTranslation(object $translation): void
    {
        if (!$this->translations->contains($translation)) {
            $this->translations->add($translation);
            $translation->setProduct($this);
        }
    }

    public function removeTranslation(ProductTranslation $translation): static
    {
        if ($this->translations->removeElement($translation)) {
            // set the owning side to null (unless already changed)
            if ($translation->getProduct() === $this) {
                $translation->setProduct(null);
            }
        }

        return $this;
    }

    public function getTranslation(string $locale = 'fr', bool $createIfNotFound = false): ?ProductTranslation
    {
        /** @var ProductTranslation $translation */
        foreach ($this->translations as $translation) {
            if ($translation->getLocale() === $locale) {
                return $translation;
            }
        }
        foreach ($this->translations as $translation) {
            if ($translation->getLocale() === 'fr') {
                return $translation;
            }
        }
        
        if ($createIfNotFound) {
            $newTranslation = new ProductTranslation();
            $newTranslation->setLocale($locale);
            $newTranslation->setProduct($this);
            $this->addTranslation($newTranslation);
            return $newTranslation;
        }

        return $this->translations->first() ?: null;
    }
    public function getTranslatableFields(): array
    {
        return ['name', 'description', 'moreinformations', 'slug', 'tags', 'specifications'];
    }

    public function getTranslationEntityClass(): string
    {
        return ProductTranslation::class;
    }

    public function findTranslationByLocale(string $locale): ?object
    {
        foreach ($this->translations as $translation) {
            if ($translation->getLocale() === $locale) {
                return $translation;
            }
        }
        return null;
    }
    public function getSpecialPrice(): ?float
    {
        return $this->specialPrice;
    }

    public function setSpecialPrice(?float $specialPrice): self
    {
        $this->specialPrice = $specialPrice;
        return $this;
    }

    public function getSpecialPriceFrom(): ?\DateTimeImmutable
    {
        return $this->specialPriceFrom;
    }

    public function setSpecialPriceFrom(?\DateTimeImmutable $specialPriceFrom): self
    {
        $this->specialPriceFrom = $specialPriceFrom;
        return $this;
    }

    public function getSpecialPriceTo(): ?\DateTimeImmutable
    {
        return $this->specialPriceTo;
    }

    public function setSpecialPriceTo(?\DateTimeImmutable $specialPriceTo): self
    {
        $this->specialPriceTo = $specialPriceTo;
        return $this;
    }

    public function isIsPreOrder(): ?bool
    {
        return $this->isPreOrder;
    }

    public function setIsPreOrder(bool $isPreOrder): self
    {
        $this->isPreOrder = $isPreOrder;
        return $this;
    }


    #[Groups(['product:read'])]
    public function getEffectivePrice(): float
    {
        $now = new \DateTimeImmutable();

        if ($this->specialPrice !== null && $this->specialPrice > 0) {
            $validFrom = $this->specialPriceFrom === null || $now >= $this->specialPriceFrom;
            $validTo   = $this->specialPriceTo === null   || $now <= $this->specialPriceTo;

            if ($validFrom && $validTo) {
                return $this->specialPrice;
            }
        }

        return $this->price ?? 0.0;
    }

    #[Groups(['product:read'])]
    public function isSalable(): bool
    {
        if ($this->isPreOrder) {
            return true;
        }
        return $this->quantity > 0;
    }

    public function getMode(): ProductMode
    {
        return $this->mode;
    }

    public function setMode(ProductMode $mode): self
    {
        $this->mode = $mode;
        return $this;
    }

    /**
     * @return Collection<int, ProductPicture>
     */
    public function getPictures(): Collection
    {
        return $this->pictures;
    }

    public function addPicture(ProductPicture $picture): self
    {
        if (!$this->pictures->contains($picture)) {
            $this->pictures->add($picture);
            $picture->setProduct($this);
        }

        return $this;
    }

    public function removePicture(ProductPicture $picture): self
    {
        if ($this->pictures->removeElement($picture)) {
            // set the owning side to null (unless already changed)
            if ($picture->getProduct() === $this) {
                $picture->setProduct(null);
            }
        }

        return $this;
    }

    public function isBookable(): bool
    {
        return $this->mode === ProductMode::BOOKING;
    }

    public function getBookingConfiguration(): ?BookingConfiguration
    {
        return $this->bookingConfiguration;
    }

    public function setBookingConfiguration(?BookingConfiguration $bookingConfiguration): static
    {
        // unset the owning side of the relation if necessary
        if ($bookingConfiguration === null && $this->bookingConfiguration !== null) {
            $this->bookingConfiguration->setProduct(null);
        }

        // set the owning side of the relation if necessary
        if ($bookingConfiguration !== null && $bookingConfiguration->getProduct() !== $this) {
            $bookingConfiguration->setProduct($this);
        }

        $this->bookingConfiguration = $bookingConfiguration;

        return $this;
    }

    /**
     * @return Collection<int, Booking>
     */
    public function getBookings(): Collection
    {
        return $this->bookings;
    }

    public function addBooking(Booking $booking): static
    {
        if (!$this->bookings->contains($booking)) {
            $this->bookings->add($booking);
            $booking->setProduct($this);
        }

        return $this;
    }

    public function removeBooking(Booking $booking): static
    {
        if ($this->bookings->removeElement($booking)) {
            // set the owning side to null (unless already changed)
            if ($booking->getProduct() === $this) {
                $booking->setProduct(null);
            }
        }

        return $this;
    }

    /**
     * Récupère le RentalPack associé au produit via ses catégories.
     */
    public function getRentalPack(): ?RentalPack
    {
        foreach ($this->getCategory() as $category) {
            foreach ($category->getRentalPacks() as $pack) {
                return $pack;
            }
        }
        return null;
    }

    /**
     * Récupère TOUS les RentalPacks associés au produit via ses catégories.
     * @return array
     */
    public function getRentalPacks(): array
    {
        $packs = [];
        foreach ($this->getCategory() as $category) {
            foreach ($category->getRentalPacks() as $pack) {
                $packs[$pack->getId()] = $pack;
            }
        }
        return array_values($packs);
    }
}

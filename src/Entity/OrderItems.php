<?php

namespace App\Entity;

use App\Repository\OrderItemsRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OrderItemsRepository::class)]
class OrderItems
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'orderItems')]
    private ?Order $orderAssociated = null;

    #[ORM\ManyToOne(inversedBy: 'orderItems')]
    private ?ProductVariant $productVariant = null;

    #[ORM\Column]
    private ?int $quantity = null;

    #[ORM\Column]
    private ?float $unitPrice = null;

    #[ORM\Column]
    private ?float $totalPrice = null;

    #[ORM\OneToOne(mappedBy: 'orderItem', targetEntity: Booking::class, cascade: ['persist', 'remove'])]
    private ?Booking $booking = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $licenseNumber = null;

    #[ORM\Column(type: 'date', nullable: true)]
    private ?\DateTimeInterface $licenseExpirationDate = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $saleUnit = null;

    // Remises de caisse (F1, 24/10/2026) : remises de la ligne [{type, value, amount, reason {code, text}, scope: line|ticket}] et leur
    // total en cents ; total_price est alors le NET (unit_price reste le brut). Null / 0 : aucune remise, comme avant.
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $discounts = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $discountAmount = 0;

    // Retour partiel (F2, 24/10/2026) : ligne de la vente d'origine dont cette ligne de retour provient
    #[ORM\ManyToOne(targetEntity: OrderItems::class)]
    #[ORM\JoinColumn(name: 'returned_from_item_id', nullable: true, onDelete: 'SET NULL')]
    private ?OrderItems $returnedFromItem = null;

    /** Combinaison de personnalisation choisie (GET /api/customization/config/{variantId}), gardée pour la fiche de commande */
    #[ORM\ManyToOne(targetEntity: ProductCustomizationImage::class)]
    #[ORM\JoinColumn(name: 'customization_id', nullable: true, onDelete: 'SET NULL')]
    private ?ProductCustomizationImage $customization = null;

    // Article libre (F7, 26/10/2026) : ligne sans variante du catalogue (product_variant_id null), libellé saisi en caisse,
    // taxable ou non (exonéré / détaxé) ; aucun mouvement de stock. Prix manuel (F7) : prix catalogue remplacé sur la ligne,
    // catalog_unit_price garde le prix catalogue (null = prix catalogue appliqué, comme avant) et le motif est conservé.
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $customLabel = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $taxable = true;

    #[ORM\Column(nullable: true)]
    private ?int $catalogUnitPrice = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $priceReasonCode = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $priceReasonText = null;

    // Instantanés (A1, 30/10/2026) : libellés figés à la vente (caisse et web), lus en priorité, catalogue en repli ; rétro-remplis
    // depuis le catalogue actuel pour les lignes antérieures
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $productName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $variantLabel = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $productCode = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $barcode = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrderAssociated(): ?Order
    {
        return $this->orderAssociated;
    }

    public function setOrderAssociated(?Order $orderAssociated): static
    {
        $this->orderAssociated = $orderAssociated;

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

    public function getQuantity(): ?int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): static
    {
        $this->quantity = $quantity;

        return $this;
    }

    public function getUnitPrice(): ?float
    {
        return $this->unitPrice;
    }

    public function setUnitPrice(float $unitPrice): static
    {
        $this->unitPrice = $unitPrice;

        return $this;
    }

    public function getTotalPrice(): ?float
    {
        return $this->totalPrice;
    }

    public function setTotalPrice(float $totalPrice): static
    {
        $this->totalPrice = $totalPrice;

        return $this;
    }

    public function __toString(): string
    {
        $unit = $this->saleUnit;
        
        if (empty($unit) && $this->productVariant && $this->productVariant->getProduct()->getSaleUnit()) {
            $unit = $this->productVariant->getProduct()->getSaleUnit()->getName();
        }

        if (empty($unit)) {
            $unit = 'pcs';
        }
            
        return $this->displayName() !== null ? $this->displayName() . ' - ' . $this->quantity . ' ' . $unit : '';
    }
    public function getBooking(): ?Booking
    {
        return $this->booking;
    }

    public function setBooking(?Booking $booking): self
    {
        if ($booking !== null && $booking->getOrderItem() !== $this) {
            $booking->setOrderItem($this);
        }
        $this->booking = $booking;
        return $this;
    }
    public function getLicenseNumber(): ?string
    {
        return $this->licenseNumber;
    }

    public function setLicenseNumber(?string $licenseNumber): self
    {
        $this->licenseNumber = $licenseNumber;
        return $this;
    }

    public function getLicenseExpirationDate(): ?\DateTimeInterface
    {
        return $this->licenseExpirationDate;
    }

    public function setLicenseExpirationDate(?\DateTimeInterface $licenseExpirationDate): self
    {
        $this->licenseExpirationDate = $licenseExpirationDate;
        return $this;
    }

    public function getDiscounts(): array { return $this->discounts ?? []; }
    public function setDiscounts(?array $discounts): static { $this->discounts = $discounts ?: null; return $this; }
    public function getDiscountAmount(): int { return $this->discountAmount; }
    public function setDiscountAmount(int $cents): static { $this->discountAmount = max(0, $cents); return $this; }
    /** Montant brut de la ligne (avant remises) en cents */
    public function grossTotal(): int { return (int) round((float) $this->totalPrice) + $this->discountAmount; }
    // Article libre et prix manuel (F7, 26/10/2026)
    public function getCustomLabel(): ?string { return $this->customLabel; }
    public function setCustomLabel(?string $label): static { $this->customLabel = $label !== null ? mb_substr(trim($label), 0, 120) : null; return $this; }
    public function isCustom(): bool { return $this->customLabel !== null && $this->productVariant === null; }
    public function isTaxable(): bool { return $this->taxable; }
    public function setTaxable(bool $taxable): static { $this->taxable = $taxable; return $this; }
    public function getCatalogUnitPrice(): ?int { return $this->catalogUnitPrice; }
    public function setCatalogUnitPrice(?int $cents): static { $this->catalogUnitPrice = $cents; return $this; }
    public function hasPriceOverride(): bool { return $this->catalogUnitPrice !== null; }
    public function getPriceReasonCode(): ?string { return $this->priceReasonCode; }
    public function setPriceReasonCode(?string $code): static { $this->priceReasonCode = $code !== null ? mb_substr($code, 0, 40) : null; return $this; }
    public function getPriceReasonText(): ?string { return $this->priceReasonText; }
    public function setPriceReasonText(?string $text): static { $this->priceReasonText = $text !== null ? mb_substr(trim($text), 0, 255) : null; return $this; }
    // Instantanés (A1)
    public function getProductName(): ?string { return $this->productName; }
    public function setProductName(?string $v): static { $this->productName = $v !== null ? mb_substr(trim($v), 0, 255) : null; return $this; }
    public function getVariantLabel(): ?string { return $this->variantLabel; }
    public function setVariantLabel(?string $v): static { $v = $v !== null ? trim($v) : ''; $this->variantLabel = $v === '' ? null : mb_substr($v, 0, 255); return $this; }
    public function getProductCode(): ?string { return $this->productCode; }
    public function setProductCode(?string $v): static { $this->productCode = $v !== null && $v !== '' ? mb_substr($v, 0, 64) : null; return $this; }
    public function getBarcode(): ?string { return $this->barcode; }
    public function setBarcode(?string $v): static { $this->barcode = $v !== null && $v !== '' ? mb_substr($v, 0, 64) : null; return $this; }
    /** Fige les libellés d'une variante au moment de la vente */
    public function snapshotFrom(?ProductVariant $variant): static
    {
        if ($variant === null) {
            return $this;
        }
        $product = $variant->getProduct();
        $this->setProductName($product?->getName())->setProductCode($product?->getCode())->setBarcode($variant->getBarcode() ?? $product?->getBarcode());
        $parts = array_filter([$variant->getColor()?->getName(), $variant->getSize()?->getName()]);
        foreach ($variant->getOptionValues() as $value) {
            $parts[] = $value->getValue();
        }

        return $this->setVariantLabel(implode(' ', $parts));
    }
    /** Nom affiché : instantané figé à la vente, sinon produit du catalogue, sinon libellé de l'article libre */
    public function displayName(): ?string { return $this->productName ?? $this->productVariant?->getProduct()?->getName() ?? $this->customLabel; }
    public function getReturnedFromItem(): ?OrderItems { return $this->returnedFromItem; }
    public function setReturnedFromItem(?OrderItems $item): static { $this->returnedFromItem = $item; return $this; }

    public function getSaleUnit(): ?string
    {
        return $this->saleUnit;
    }

    public function setSaleUnit(?string $saleUnit): self
    {
        $this->saleUnit = $saleUnit;
        return $this;
    }

    public function getCustomization(): ?ProductCustomizationImage
    {
        return $this->customization;
    }

    public function setCustomization(?ProductCustomizationImage $customization): static
    {
        $this->customization = $customization;

        return $this;
    }
}

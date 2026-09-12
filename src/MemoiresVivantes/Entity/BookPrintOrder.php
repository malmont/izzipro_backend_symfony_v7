<?php

namespace App\MemoiresVivantes\Entity;

use ApiPlatform\Metadata\ApiResource;
use App\Entity\User;
use App\MemoiresVivantes\Repository\BookPrintOrderRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: BookPrintOrderRepository::class)]
#[ORM\Table(name: 'mv_book_print_order')]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    normalizationContext: ['groups' => ['book_print:read']],
    denormalizationContext: ['groups' => ['book_print:write']]
)]
class BookPrintOrder
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[Groups(['book_print:read'])]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Book::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['book_print:read', 'book_print:write'])]
    private ?Book $book = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['book_print:read'])]
    private ?User $user = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['book_print:read'])]
    private ?string $luluPrintJobId = null;

    #[ORM\Column(length: 50)]
    #[Groups(['book_print:read', 'book_print:write'])]
    private string $status = 'draft'; // draft, estimated, created, in_production, shipped, canceled, error

    // --- Coordonnées de livraison du demandeur ---
    #[ORM\Column(length: 255)]
    #[Groups(['book_print:read', 'book_print:write'])]
    private ?string $recipientName = null;

    #[ORM\Column(length: 255)]
    #[Groups(['book_print:read', 'book_print:write'])]
    private ?string $street1 = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['book_print:read', 'book_print:write'])]
    private ?string $street2 = null;

    #[ORM\Column(length: 100)]
    #[Groups(['book_print:read', 'book_print:write'])]
    private ?string $city = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['book_print:read', 'book_print:write'])]
    private ?string $state = null;

    #[ORM\Column(length: 20)]
    #[Groups(['book_print:read', 'book_print:write'])]
    private ?string $postalCode = null;

    #[ORM\Column(length: 2)]
    #[Groups(['book_print:read', 'book_print:write'])]
    private string $countryCode = 'CA';

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['book_print:read', 'book_print:write'])]
    private ?string $phoneNumber = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['book_print:read', 'book_print:write'])]
    private ?string $email = null;

    // --- Tarifs et Expédition ---
    #[ORM\Column(length: 50)]
    #[Groups(['book_print:read', 'book_print:write'])]
    private string $shippingLevel = 'EXPEDITED'; // EXPEDITED (FedEx Express/2-3j), GROUND (FedEx Ground), EXPRESS, MAIL

    #[ORM\Column(type: Types::INTEGER)]
    #[Groups(['book_print:read', 'book_print:write'])]
    private int $quantity = 1;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    #[Groups(['book_print:read'])]
    private ?string $printCost = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    #[Groups(['book_print:read'])]
    private ?string $shippingCost = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    #[Groups(['book_print:read'])]
    private ?string $taxCost = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    #[Groups(['book_print:read'])]
    private ?string $totalCost = null;

    #[ORM\Column(length: 3)]
    #[Groups(['book_print:read'])]
    private string $currency = 'CAD';

    // --- Suivi Transporteur & PDFs ---
    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['book_print:read'])]
    private ?string $carrierName = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['book_print:read'])]
    private ?string $trackingNumber = null;

    #[ORM\Column(length: 500, nullable: true)]
    #[Groups(['book_print:read'])]
    private ?string $trackingUrl = null;

    #[ORM\Column(length: 500, nullable: true)]
    #[Groups(['book_print:read'])]
    private ?string $interiorPdfUrl = null;

    #[ORM\Column(length: 500, nullable: true)]
    #[Groups(['book_print:read'])]
    private ?string $coverPdfUrl = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['book_print:read', 'book_print:write'])]
    private ?string $coverStyle = 'biographic_split';

    #[ORM\Column(length: 20, nullable: true)]
    #[Groups(['book_print:read', 'book_print:write'])]
    private ?string $bgColor = null;

    #[ORM\Column(length: 500, nullable: true)]
    #[Groups(['book_print:read', 'book_print:write'])]
    private ?string $customCoverPdfUrl = null;

    #[ORM\Column(length: 500, nullable: true)]
    #[Groups(['book_print:read', 'book_print:write'])]
    private ?string $customInteriorPdfUrl = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $luluRawResponse = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['book_print:read'])]
    private ?string $errorMessage = null;

    // --- Timestamps ---
    #[ORM\Column]
    #[Groups(['book_print:read'])]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    #[Groups(['book_print:read'])]
    private ?\DateTimeInterface $updatedAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    #[Groups(['book_print:read'])]
    private ?\DateTimeInterface $shippedAt = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTime();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTime();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getBook(): ?Book
    {
        return $this->book;
    }

    public function setBook(?Book $book): static
    {
        $this->book = $book;
        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getLuluPrintJobId(): ?string
    {
        return $this->luluPrintJobId;
    }

    public function setLuluPrintJobId(?string $luluPrintJobId): static
    {
        $this->luluPrintJobId = $luluPrintJobId;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getRecipientName(): ?string
    {
        return $this->recipientName;
    }

    public function setRecipientName(?string $recipientName): static
    {
        $this->recipientName = $recipientName;
        return $this;
    }

    public function getStreet1(): ?string
    {
        return $this->street1;
    }

    public function setStreet1(?string $street1): static
    {
        $this->street1 = $street1;
        return $this;
    }

    public function getStreet2(): ?string
    {
        return $this->street2;
    }

    public function setStreet2(?string $street2): static
    {
        $this->street2 = $street2;
        return $this;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function setCity(?string $city): static
    {
        $this->city = $city;
        return $this;
    }

    public function getState(): ?string
    {
        return $this->state;
    }

    public function setState(?string $state): static
    {
        $this->state = $state;
        return $this;
    }

    public function getPostalCode(): ?string
    {
        return $this->postalCode;
    }

    public function setPostalCode(?string $postalCode): static
    {
        $this->postalCode = $postalCode;
        return $this;
    }

    public function getCountryCode(): string
    {
        return $this->countryCode;
    }

    public function setCountryCode(string $countryCode): static
    {
        $this->countryCode = strtoupper($countryCode);
        return $this;
    }

    public function getPhoneNumber(): ?string
    {
        return $this->phoneNumber;
    }

    public function setPhoneNumber(?string $phoneNumber): static
    {
        $this->phoneNumber = $phoneNumber;
        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;
        return $this;
    }

    public function getShippingLevel(): string
    {
        return $this->shippingLevel;
    }

    public function setShippingLevel(string $shippingLevel): static
    {
        $this->shippingLevel = $shippingLevel;
        return $this;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): static
    {
        $this->quantity = $quantity;
        return $this;
    }

    public function getPrintCost(): ?string
    {
        return $this->printCost;
    }

    public function setPrintCost(?string $printCost): static
    {
        $this->printCost = $printCost;
        return $this;
    }

    public function getShippingCost(): ?string
    {
        return $this->shippingCost;
    }

    public function setShippingCost(?string $shippingCost): static
    {
        $this->shippingCost = $shippingCost;
        return $this;
    }

    public function getTaxCost(): ?string
    {
        return $this->taxCost;
    }

    public function setTaxCost(?string $taxCost): static
    {
        $this->taxCost = $taxCost;
        return $this;
    }

    public function getTotalCost(): ?string
    {
        return $this->totalCost;
    }

    public function setTotalCost(?string $totalCost): static
    {
        $this->totalCost = $totalCost;
        return $this;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): static
    {
        $this->currency = strtoupper($currency);
        return $this;
    }

    public function getCarrierName(): ?string
    {
        return $this->carrierName;
    }

    public function setCarrierName(?string $carrierName): static
    {
        $this->carrierName = $carrierName;
        return $this;
    }

    public function getTrackingNumber(): ?string
    {
        return $this->trackingNumber;
    }

    public function setTrackingNumber(?string $trackingNumber): static
    {
        $this->trackingNumber = $trackingNumber;
        return $this;
    }

    public function getTrackingUrl(): ?string
    {
        return $this->trackingUrl;
    }

    public function setTrackingUrl(?string $trackingUrl): static
    {
        $this->trackingUrl = $trackingUrl;
        return $this;
    }

    public function getInteriorPdfUrl(): ?string
    {
        return $this->interiorPdfUrl;
    }

    public function setInteriorPdfUrl(?string $interiorPdfUrl): static
    {
        $this->interiorPdfUrl = $interiorPdfUrl;
        return $this;
    }

    public function getCoverPdfUrl(): ?string
    {
        return $this->coverPdfUrl;
    }

    public function setCoverPdfUrl(?string $coverPdfUrl): static
    {
        $this->coverPdfUrl = $coverPdfUrl;
        return $this;
    }

    public function getLuluRawResponse(): ?array
    {
        return $this->luluRawResponse;
    }

    public function setLuluRawResponse(?array $luluRawResponse): static
    {
        $this->luluRawResponse = $luluRawResponse;
        return $this;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): static
    {
        $this->errorMessage = $errorMessage;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeInterface $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function getShippedAt(): ?\DateTimeInterface
    {
        return $this->shippedAt;
    }

    public function setShippedAt(?\DateTimeInterface $shippedAt): static
    {
        $this->shippedAt = $shippedAt;
        return $this;
    }

    public function getCoverStyle(): ?string
    {
        return $this->coverStyle;
    }

    public function setCoverStyle(?string $coverStyle): static
    {
        $this->coverStyle = $coverStyle;
        return $this;
    }

    public function getBgColor(): ?string
    {
        return $this->bgColor;
    }

    public function setBgColor(?string $bgColor): static
    {
        $this->bgColor = $bgColor;
        return $this;
    }

    public function getCustomCoverPdfUrl(): ?string
    {
        return $this->customCoverPdfUrl;
    }

    public function setCustomCoverPdfUrl(?string $customCoverPdfUrl): static
    {
        $this->customCoverPdfUrl = $customCoverPdfUrl;
        return $this;
    }

    public function getCustomInteriorPdfUrl(): ?string
    {
        return $this->customInteriorPdfUrl;
    }

    public function setCustomInteriorPdfUrl(?string $customInteriorPdfUrl): static
    {
        $this->customInteriorPdfUrl = $customInteriorPdfUrl;
        return $this;
    }
}

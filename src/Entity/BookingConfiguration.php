<?php

namespace App\Entity;

use App\Repository\BookingConfigurationRepository;
use Doctrine\ORM\Mapping as ORM;

use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity(repositoryClass: BookingConfigurationRepository::class)]
class BookingConfiguration
{
    #[Groups(['vehicle_product:read', 'product:read'])]
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'bookingConfiguration', cascade: ['persist'])]
    private ?Product $product = null;

    #[Groups(['vehicle_product:read', 'product:read'])]
    #[ORM\Column(length: 40)]
    private ?string $granularity = null;

    #[Groups(['vehicle_product:read', 'product:read'])]
    #[ORM\Column]
    private ?int $stockQuantity = null;

    #[Groups(['vehicle_product:read', 'product:read'])]
    #[ORM\Column]
    private ?int $minDuration = null;

    #[Groups(['vehicle_product:read', 'product:read'])]
    #[ORM\Column]
    private ?int $bufferTime = null;

    // Réglages de la boutique réglable (08/10/2026), tous facultatifs ; montants en cents
    #[ORM\Column(name: 'max_duration', nullable: true)]
    private ?int $maxDuration = null;

    /** Heures d'ouverture « HH:MM » */
    #[ORM\Column(name: 'opening_start', length: 5, nullable: true)]
    private ?string $openingStart = null;

    #[ORM\Column(name: 'opening_end', length: 5, nullable: true)]
    private ?string $openingEnd = null;

    /** [{ label, start, end }] */
    #[ORM\Column(name: 'half_days', nullable: true)]
    private ?array $halfDays = null;

    /** Dates autorisées « AAAA-MM-JJ » (vide ou null : toutes) */
    #[ORM\Column(name: 'allowed_dates', nullable: true)]
    private ?array $allowedDates = null;

    /** { start, end } */
    #[ORM\Column(name: 'evening_slot', nullable: true)]
    private ?array $eveningSlot = null;

    #[ORM\Column(name: 'min_days_standard', nullable: true)]
    private ?int $minDaysStandard = null;

    #[ORM\Column(nullable: true)]
    private ?int $deposit = null;

    #[ORM\Column(name: 'extra_passenger_fee', nullable: true)]
    private ?int $extraPassengerFee = null;

    #[ORM\Column(name: 'arrival_lead_minutes', nullable: true)]
    private ?int $arrivalLeadMinutes = null;

    #[ORM\Column(name: 'cancellation_policy', type: 'text', nullable: true)]
    private ?string $cancellationPolicy = null;

    /** Textes */
    #[ORM\Column(nullable: true)]
    private ?array $included = null;

    #[ORM\Column(nullable: true)]
    private ?array $excluded = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMaxDuration(): ?int { return $this->maxDuration; }
    public function setMaxDuration(?int $maxDuration): static { $this->maxDuration = $maxDuration; return $this; }
    public function getOpeningStart(): ?string { return $this->openingStart; }
    public function setOpeningStart(?string $openingStart): static { $this->openingStart = $openingStart; return $this; }
    public function getOpeningEnd(): ?string { return $this->openingEnd; }
    public function setOpeningEnd(?string $openingEnd): static { $this->openingEnd = $openingEnd; return $this; }
    public function getHalfDays(): ?array { return $this->halfDays; }
    public function setHalfDays(?array $halfDays): static { $this->halfDays = $halfDays; return $this; }
    public function getAllowedDates(): ?array { return $this->allowedDates; }
    public function setAllowedDates(?array $allowedDates): static { $this->allowedDates = $allowedDates; return $this; }
    public function getEveningSlot(): ?array { return $this->eveningSlot; }
    public function setEveningSlot(?array $eveningSlot): static { $this->eveningSlot = $eveningSlot; return $this; }
    public function getMinDaysStandard(): ?int { return $this->minDaysStandard; }
    public function setMinDaysStandard(?int $minDaysStandard): static { $this->minDaysStandard = $minDaysStandard; return $this; }
    public function getDeposit(): ?int { return $this->deposit; }
    public function setDeposit(?int $deposit): static { $this->deposit = $deposit; return $this; }
    public function getExtraPassengerFee(): ?int { return $this->extraPassengerFee; }
    public function setExtraPassengerFee(?int $extraPassengerFee): static { $this->extraPassengerFee = $extraPassengerFee; return $this; }
    public function getArrivalLeadMinutes(): ?int { return $this->arrivalLeadMinutes; }
    public function setArrivalLeadMinutes(?int $arrivalLeadMinutes): static { $this->arrivalLeadMinutes = $arrivalLeadMinutes; return $this; }
    public function getCancellationPolicy(): ?string { return $this->cancellationPolicy; }
    public function setCancellationPolicy(?string $cancellationPolicy): static { $this->cancellationPolicy = $cancellationPolicy; return $this; }
    public function getIncluded(): ?array { return $this->included; }
    public function setIncluded(?array $included): static { $this->included = $included; return $this; }
    public function getExcluded(): ?array { return $this->excluded; }
    public function setExcluded(?array $excluded): static { $this->excluded = $excluded; return $this; }
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $notes): static { $this->notes = $notes; return $this; }

    public function getProduct(): ?Product
    {
        return $this->product;
    }

    public function setProduct(?Product $product): static
    {
        $this->product = $product;

        return $this;
    }

    public function getGranularity(): ?string
    {
        return $this->granularity;
    }

    public function setGranularity(string $granularity): static
    {
        $this->granularity = $granularity;

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

    public function getMinDuration(): ?int
    {
        return $this->minDuration;
    }

    public function setMinDuration(int $minDuration): static
    {
        $this->minDuration = $minDuration;

        return $this;
    }

    public function getBufferTime(): ?int
    {
        return $this->bufferTime;
    }

    public function setBufferTime(int $bufferTime): static
    {
        $this->bufferTime = $bufferTime;

        return $this;
    }

    public function __toString(): string
    {
        if ($this->id) {
            return sprintf('Config #%d (Stock: %d, %s)', $this->id, $this->stockQuantity ?? 0, $this->granularity ?? 'N/A');
        }
        return 'Nouvelle Configuration';
    }
}

<?php

namespace App\Entity;

use App\Repository\ReservationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ReservationRepository::class)]
#[ORM\Table(name: 'reservation')]
class Reservation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Entreprise::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Entreprise $entreprise = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $tenantId = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $serviceId = null;

    #[Assert\NotBlank(message: 'Le nom du service est requis.')]
    #[ORM\Column(length: 255)]
    private ?string $serviceName = null;

    #[Assert\NotBlank(message: 'La date de réservation est requise.')]
    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private ?\DateTimeInterface $reservationDate = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $reservationSlot = null;

    #[Assert\NotBlank(message: 'Le nom du client est requis.')]
    #[Assert\Length(max: 255)]
    #[ORM\Column(length: 255)]
    private ?string $clientName = null;

    #[Assert\NotBlank(message: 'L\'adresse email est requise.')]
    #[Assert\Email(message: 'L\'adresse email n\'est pas valide.')]
    #[ORM\Column(length: 255)]
    private ?string $clientEmail = null;

    #[Assert\NotBlank(message: 'Le numéro de téléphone est requis.')]
    #[ORM\Column(length: 50)]
    private ?string $clientPhone = null;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 1])]
    private int $numberOfGuests = 1;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(length: 50, options: ['default' => 'pending'])]
    private string $status = 'pending';

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(length: 36, nullable: true)]
    private ?string $bookId = null;

    #[ORM\Column(length: 36, nullable: true)]
    private ?string $chapterId = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $stepNumber = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $totalSteps = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $forfaitName = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->status = 'pending';
        $this->numberOfGuests = 1;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEntreprise(): ?Entreprise
    {
        return $this->entreprise;
    }

    public function setEntreprise(?Entreprise $entreprise): self
    {
        $this->entreprise = $entreprise;
        return $this;
    }

    public function getTenantId(): ?string
    {
        return $this->tenantId;
    }

    public function setTenantId(?string $tenantId): self
    {
        $this->tenantId = $tenantId;
        return $this;
    }

    public function getServiceId(): ?string
    {
        return $this->serviceId;
    }

    public function setServiceId(?string $serviceId): self
    {
        $this->serviceId = $serviceId;
        return $this;
    }

    public function getServiceName(): ?string
    {
        return $this->serviceName;
    }

    public function setServiceName(string $serviceName): self
    {
        $this->serviceName = $serviceName;
        return $this;
    }

    public function getReservationDate(): ?\DateTimeInterface
    {
        return $this->reservationDate;
    }

    public function setReservationDate(\DateTimeInterface $reservationDate): self
    {
        $this->reservationDate = $reservationDate;
        return $this;
    }

    public function getReservationSlot(): ?string
    {
        return $this->reservationSlot;
    }

    public function setReservationSlot(?string $reservationSlot): self
    {
        $this->reservationSlot = $reservationSlot;
        return $this;
    }

    public function getClientName(): ?string
    {
        return $this->clientName;
    }

    public function setClientName(string $clientName): self
    {
        $this->clientName = $clientName;
        return $this;
    }

    public function getClientEmail(): ?string
    {
        return $this->clientEmail;
    }

    public function setClientEmail(string $clientEmail): self
    {
        $this->clientEmail = $clientEmail;
        return $this;
    }

    public function getClientPhone(): ?string
    {
        return $this->clientPhone;
    }

    public function setClientPhone(string $clientPhone): self
    {
        $this->clientPhone = $clientPhone;
        return $this;
    }

    public function getNumberOfGuests(): int
    {
        return $this->numberOfGuests;
    }

    public function setNumberOfGuests(int $numberOfGuests): self
    {
        $this->numberOfGuests = $numberOfGuests;
        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): self
    {
        $this->notes = $notes;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getBookId(): ?string
    {
        return $this->bookId;
    }

    public function setBookId(?string $bookId): self
    {
        $this->bookId = $bookId;
        return $this;
    }

    public function getChapterId(): ?string
    {
        return $this->chapterId;
    }

    public function setChapterId(?string $chapterId): self
    {
        $this->chapterId = $chapterId;
        return $this;
    }

    public function getStepNumber(): ?int
    {
        return $this->stepNumber;
    }

    public function setStepNumber(?int $stepNumber): self
    {
        $this->stepNumber = $stepNumber;
        return $this;
    }

    public function getTotalSteps(): ?int
    {
        return $this->totalSteps;
    }

    public function setTotalSteps(?int $totalSteps): self
    {
        $this->totalSteps = $totalSteps;
        return $this;
    }

    public function getForfaitName(): ?string
    {
        return $this->forfaitName;
    }

    public function setForfaitName(?string $forfaitName): self
    {
        $this->forfaitName = $forfaitName;
        return $this;
    }

    public function getStepLabel(): ?string
    {
        if ($this->stepNumber !== null && $this->totalSteps !== null) {
            return sprintf('Séance %d/%d', $this->stepNumber, $this->totalSteps);
        }
        if ($this->stepNumber !== null) {
            return sprintf('Séance %d', $this->stepNumber);
        }
        return null;
    }

    public function getQuickActions(): ?string
    {
        return null;
    }
}

<?php

namespace App\ESG\Entity;

use App\ESG\Enum\SectorEnum;
use App\ESG\Enum\SizeEnum;
use App\ESG\Enum\TerritoryEnum;
use App\ESG\Repository\EsgCompanyRepository;
use Cocur\Slugify\Slugify;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EsgCompanyRepository::class)]
#[ORM\Table(name: 'esg_company')]
#[ORM\HasLifecycleCallbacks]
class EsgCompany
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 255, unique: true)]
    private ?string $slug = null;

    #[ORM\Column(type: 'string', length: 50, enumType: SectorEnum::class)]
    private ?SectorEnum $sector = null;

    #[ORM\Column(type: 'string', length: 50, enumType: SizeEnum::class)]
    private ?SizeEnum $sizeCategory = null;

    #[ORM\Column(type: 'string', length: 50, enumType: TerritoryEnum::class)]
    private ?TerritoryEnum $territory = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $website = null;

    #[ORM\Column(type: 'json')]
    private array $existingCertifications = [];

    #[ORM\Column(type: 'json')]
    private array $uploadedDocumentCodes = [];

    #[ORM\Column(length: 255)]
    private ?string $contactEmail = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $contactPhone = null;

    #[ORM\Column(type: 'boolean')]
    private bool $isActive = true;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $updatedAt = null;

    /**
     * @var Collection<int, EsgUser>
     */
    #[ORM\OneToMany(mappedBy: 'company', targetEntity: EsgUser::class)]
    private Collection $users;

    /**
     * @var Collection<int, DiagnosticSession>
     */
    #[ORM\OneToMany(mappedBy: 'company', targetEntity: DiagnosticSession::class)]
    private Collection $diagnosticSessions;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->users = new ArrayCollection();
        $this->diagnosticSessions = new ArrayCollection();
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->generateSlug(true);
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
        // Only regenerate slug if name changed but keep existing suffix to preserve uniqueness
        if ($this->slug === null) {
            $this->generateSlug(true);
        } else {
            $this->generateSlug(false);
        }
    }

    private function generateSlug(bool $addUniqueSuffix = false): void
    {
        if ($this->name !== null) {
            $slugify = new Slugify();
            $base = $slugify->slugify($this->name);
            if ($addUniqueSuffix) {
                // Append 8 random hex chars to guarantee uniqueness across registrations
                $this->slug = $base . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
            } else {
                // Preserve the existing unique suffix on update: only update base part
                if ($this->slug !== null) {
                    // Keep existing slug to avoid breaking URLs
                    return;
                }
                $this->slug = $base;
            }
        }
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

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): self
    {
        $this->slug = $slug;
        return $this;
    }

    public function getSector(): ?SectorEnum
    {
        return $this->sector;
    }

    public function setSector(SectorEnum $sector): self
    {
        $this->sector = $sector;
        return $this;
    }

    public function getSizeCategory(): ?SizeEnum
    {
        return $this->sizeCategory;
    }

    public function setSizeCategory(SizeEnum $sizeCategory): self
    {
        $this->sizeCategory = $sizeCategory;
        return $this;
    }

    public function getTerritory(): ?TerritoryEnum
    {
        return $this->territory;
    }

    public function setTerritory(TerritoryEnum $territory): self
    {
        $this->territory = $territory;
        return $this;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function setCity(?string $city): self
    {
        $this->city = $city;
        return $this;
    }

    public function getWebsite(): ?string
    {
        return $this->website;
    }

    public function setWebsite(?string $website): self
    {
        $this->website = $website;
        return $this;
    }

    public function getExistingCertifications(): array
    {
        return $this->existingCertifications;
    }

    public function setExistingCertifications(array $existingCertifications): self
    {
        $this->existingCertifications = $existingCertifications;
        return $this;
    }

    public function getUploadedDocumentCodes(): array
    {
        return $this->uploadedDocumentCodes;
    }

    public function setUploadedDocumentCodes(array $uploadedDocumentCodes): self
    {
        $this->uploadedDocumentCodes = $uploadedDocumentCodes;
        return $this;
    }

    public function addUploadedDocumentCode(string $code): self
    {
        if (!in_array($code, $this->uploadedDocumentCodes, true)) {
            $this->uploadedDocumentCodes[] = $code;
        }
        return $this;
    }

    public function removeUploadedDocumentCode(string $code): self
    {
        $this->uploadedDocumentCodes = array_values(array_filter(
            $this->uploadedDocumentCodes,
            fn($c) => $c !== $code
        ));
        return $this;
    }

    public function getContactEmail(): ?string
    {
        return $this->contactEmail;
    }

    public function setContactEmail(string $contactEmail): self
    {
        $this->contactEmail = $contactEmail;
        return $this;
    }

    public function getContactPhone(): ?string
    {
        return $this->contactPhone;
    }

    public function setContactPhone(?string $contactPhone): self
    {
        $this->contactPhone = $contactPhone;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }

    /**
     * @return Collection<int, EsgUser>
     */
    public function getUsers(): Collection
    {
        return $this->users;
    }

    public function addUser(EsgUser $user): self
    {
        if (!$this->users->contains($user)) {
            $this->users->add($user);
            $user->setCompany($this);
        }
        return $this;
    }

    public function removeUser(EsgUser $user): self
    {
        if ($this->users->removeElement($user)) {
            if ($user->getCompany() === $this) {
                $user->setCompany(null);
            }
        }
        return $this;
    }

    /**
     * @return Collection<int, DiagnosticSession>
     */
    public function getDiagnosticSessions(): Collection
    {
        return $this->diagnosticSessions;
    }
}

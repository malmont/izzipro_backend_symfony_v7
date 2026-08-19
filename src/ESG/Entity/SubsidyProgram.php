<?php

namespace App\ESG\Entity;

use App\ESG\Enum\TerritoryEnum;
use App\ESG\Repository\SubsidyProgramRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SubsidyProgramRepository::class)]
#[ORM\Table(name: 'esg_subsidy_program')]
class SubsidyProgram
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, unique: true)]
    private ?string $code = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 255)]
    private ?string $organism = null;

    #[ORM\Column(type: 'string', length: 50, enumType: TerritoryEnum::class)]
    private ?TerritoryEnum $territory = null;

    #[ORM\Column(type: 'float')]
    private float $subsidyRatePercent = 0.0;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $maxAmountCad = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $conditions = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $applicationUrl = null;

    #[ORM\Column(type: 'boolean')]
    private bool $isActive = true;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $disclaimer = null;

    /**
     * @var Collection<int, CertificationReferential>
     */
    #[ORM\ManyToMany(targetEntity: CertificationReferential::class, mappedBy: 'subsidyPrograms')]
    private Collection $referentials;

    public function __construct()
    {
        $this->referentials = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(string $code): self
    {
        $this->code = $code;
        return $this;
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

    public function getOrganism(): ?string
    {
        return $this->organism;
    }

    public function setOrganism(string $organism): self
    {
        $this->organism = $organism;
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

    public function getSubsidyRatePercent(): float
    {
        return $this->subsidyRatePercent;
    }

    public function setSubsidyRatePercent(float $subsidyRatePercent): self
    {
        $this->subsidyRatePercent = $subsidyRatePercent;
        return $this;
    }

    public function getMaxAmountCad(): ?int
    {
        return $this->maxAmountCad;
    }

    public function setMaxAmountCad(?int $maxAmountCad): self
    {
        $this->maxAmountCad = $maxAmountCad;
        return $this;
    }

    public function getConditions(): ?string
    {
        return $this->conditions;
    }

    public function setConditions(?string $conditions): self
    {
        $this->conditions = $conditions;
        return $this;
    }

    public function getApplicationUrl(): ?string
    {
        return $this->applicationUrl;
    }

    public function setApplicationUrl(?string $applicationUrl): self
    {
        $this->applicationUrl = $applicationUrl;
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

    public function getDisclaimer(): ?string
    {
        return $this->disclaimer;
    }

    public function setDisclaimer(?string $disclaimer): self
    {
        $this->disclaimer = $disclaimer;
        return $this;
    }

    /**
     * @return Collection<int, CertificationReferential>
     */
    public function getReferentials(): Collection
    {
        return $this->referentials;
    }

    public function addReferential(CertificationReferential $referential): self
    {
        if (!$this->referentials->contains($referential)) {
            $this->referentials->add($referential);
            $referential->addSubsidyProgram($this);
        }
        return $this;
    }

    public function removeReferential(CertificationReferential $referential): self
    {
        if ($this->referentials->removeElement($referential)) {
            $referential->removeSubsidyProgram($this);
        }
        return $this;
    }
}

<?php

namespace App\ESG\Entity;

use App\ESG\Repository\CertificationReferentialRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CertificationReferentialRepository::class)]
#[ORM\Table(name: 'esg_cert_referential')]
#[ORM\UniqueConstraint(name: 'uniq_code_version', columns: ['code', 'version'])]
class CertificationReferential
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $code = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 255)]
    private ?string $category = null;

    #[ORM\Column(length: 50)]
    private ?string $version = null;

    #[ORM\Column(type: 'boolean')]
    private bool $isActive = true;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $activatedAt = null;

    #[ORM\Column(type: 'float')]
    private float $thresholdEnvironment = 0.0;

    #[ORM\Column(type: 'float')]
    private float $thresholdGovernance = 0.0;

    #[ORM\Column(type: 'float')]
    private float $thresholdSocial = 0.0;

    #[ORM\Column(type: 'float')]
    private float $thresholdClimate = 0.0;

    #[ORM\Column(type: 'float')]
    private float $thresholdGlobal = 0.0;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $certLevel = null;

    #[ORM\Column(type: 'integer')]
    private int $durationMinMonths = 0;

    #[ORM\Column(type: 'integer')]
    private int $durationMaxMonths = 0;

    #[ORM\Column(type: 'integer')]
    private int $costMinCad = 0;

    #[ORM\Column(type: 'integer')]
    private int $costMaxCad = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $marketImpact = null;

    #[ORM\Column(type: 'json')]
    private array $territory = [];

    /**
     * @var Collection<int, SubsidyProgram>
     */
    #[ORM\ManyToMany(targetEntity: SubsidyProgram::class, inversedBy: 'referentials')]
    #[ORM\JoinTable(name: 'esg_cert_subsidy')]
    #[ORM\JoinColumn(name: 'referential_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'subsidy_program_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $subsidyPrograms;

    /**
     * @var Collection<int, OddMapping>
     */
    #[ORM\OneToMany(mappedBy: 'referential', targetEntity: OddMapping::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $oddMappings;

    /**
     * @var Collection<int, CertificationRecommendation>
     */
    #[ORM\OneToMany(mappedBy: 'referential', targetEntity: CertificationRecommendation::class)]
    private Collection $recommendations;

    public function __construct()
    {
        $this->activatedAt = new \DateTimeImmutable();
        $this->subsidyPrograms = new ArrayCollection();
        $this->oddMappings = new ArrayCollection();
        $this->recommendations = new ArrayCollection();
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

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function setCategory(string $category): self
    {
        $this->category = $category;
        return $this;
    }

    public function getVersion(): ?string
    {
        return $this->version;
    }

    public function setVersion(string $version): self
    {
        $this->version = $version;
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

    public function getActivatedAt(): ?\DateTimeInterface
    {
        return $this->activatedAt;
    }

    public function setActivatedAt(\DateTimeInterface $activatedAt): self
    {
        $this->activatedAt = $activatedAt;
        return $this;
    }

    public function getThresholdEnvironment(): float
    {
        return $this->thresholdEnvironment;
    }

    public function setThresholdEnvironment(float $thresholdEnvironment): self
    {
        $this->thresholdEnvironment = $thresholdEnvironment;
        return $this;
    }

    public function getThresholdGovernance(): float
    {
        return $this->thresholdGovernance;
    }

    public function setThresholdGovernance(float $thresholdGovernance): self
    {
        $this->thresholdGovernance = $thresholdGovernance;
        return $this;
    }

    public function getThresholdSocial(): float
    {
        return $this->thresholdSocial;
    }

    public function setThresholdSocial(float $thresholdSocial): self
    {
        $this->thresholdSocial = $thresholdSocial;
        return $this;
    }

    public function getThresholdClimate(): float
    {
        return $this->thresholdClimate;
    }

    public function setThresholdClimate(float $thresholdClimate): self
    {
        $this->thresholdClimate = $thresholdClimate;
        return $this;
    }

    public function getThresholdGlobal(): float
    {
        return $this->thresholdGlobal;
    }

    public function setThresholdGlobal(float $thresholdGlobal): self
    {
        $this->thresholdGlobal = $thresholdGlobal;
        return $this;
    }

    public function getCertLevel(): ?string
    {
        return $this->certLevel;
    }

    public function setCertLevel(?string $certLevel): self
    {
        $this->certLevel = $certLevel;
        return $this;
    }

    public function getDurationMinMonths(): int
    {
        return $this->durationMinMonths;
    }

    public function setDurationMinMonths(int $durationMinMonths): self
    {
        $this->durationMinMonths = $durationMinMonths;
        return $this;
    }

    public function getDurationMaxMonths(): int
    {
        return $this->durationMaxMonths;
    }

    public function setDurationMaxMonths(int $durationMaxMonths): self
    {
        $this->durationMaxMonths = $durationMaxMonths;
        return $this;
    }

    public function getCostMinCad(): int
    {
        return $this->costMinCad;
    }

    public function setCostMinCad(int $costMinCad): self
    {
        $this->costMinCad = $costMinCad;
        return $this;
    }

    public function getCostMaxCad(): int
    {
        return $this->costMaxCad;
    }

    public function setCostMaxCad(int $costMaxCad): self
    {
        $this->costMaxCad = $costMaxCad;
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

    public function getMarketImpact(): ?string
    {
        return $this->marketImpact;
    }

    public function setMarketImpact(?string $marketImpact): self
    {
        $this->marketImpact = $marketImpact;
        return $this;
    }

    public function getTerritory(): array
    {
        return $this->territory;
    }

    public function setTerritory(array $territory): self
    {
        $this->territory = $territory;
        return $this;
    }

    /**
     * @return Collection<int, SubsidyProgram>
     */
    public function getSubsidyPrograms(): Collection
    {
        return $this->subsidyPrograms;
    }

    public function addSubsidyProgram(SubsidyProgram $subsidyProgram): self
    {
        if (!$this->subsidyPrograms->contains($subsidyProgram)) {
            $this->subsidyPrograms->add($subsidyProgram);
        }
        return $this;
    }

    public function removeSubsidyProgram(SubsidyProgram $subsidyProgram): self
    {
        $this->subsidyPrograms->removeElement($subsidyProgram);
        return $this;
    }

    /**
     * @return Collection<int, OddMapping>
     */
    public function getOddMappings(): Collection
    {
        return $this->oddMappings;
    }

    public function addOddMapping(OddMapping $oddMapping): self
    {
        if (!$this->oddMappings->contains($oddMapping)) {
            $this->oddMappings->add($oddMapping);
            $oddMapping->setReferential($this);
        }
        return $this;
    }

    public function removeOddMapping(OddMapping $oddMapping): self
    {
        if ($this->oddMappings->removeElement($oddMapping)) {
            if ($oddMapping->getReferential() === $this) {
                $oddMapping->setReferential(null);
            }
        }
        return $this;
    }

    /**
     * @return Collection<int, CertificationRecommendation>
     */
    public function getRecommendations(): Collection
    {
        return $this->recommendations;
    }
}

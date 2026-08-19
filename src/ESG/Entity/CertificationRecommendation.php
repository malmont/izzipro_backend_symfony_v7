<?php

namespace App\ESG\Entity;

use App\ESG\Repository\CertificationRecommendationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CertificationRecommendationRepository::class)]
#[ORM\Table(name: 'esg_cert_recommendation')]
#[ORM\Index(columns: ['session_id', 'priority'], name: 'idx_reco_session_priority')]
class CertificationRecommendation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'integer')]
    private int $priority = 1;

    #[ORM\Column(type: 'float')]
    private float $gapToThresholdGlobal = 0.0;

    #[ORM\Column(type: 'boolean')]
    private bool $isEligible = false;

    #[ORM\Column(type: 'integer')]
    private int $estimatedDurationMonths = 0;

    #[ORM\Column(type: 'integer')]
    private int $grossCostCad = 0;

    #[ORM\Column(type: 'integer')]
    private int $totalSubsidyCad = 0;

    #[ORM\Column(type: 'integer')]
    private int $netCostCad = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $impactNarrative = null;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $generatedAt = null;

    #[ORM\ManyToOne(targetEntity: DiagnosticSession::class, inversedBy: 'recommendations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?DiagnosticSession $session = null;

    #[ORM\ManyToOne(targetEntity: CertificationReferential::class, inversedBy: 'recommendations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?CertificationReferential $referential = null;

    /**
     * @var Collection<int, SubsidyProgram>
     */
    #[ORM\ManyToMany(targetEntity: SubsidyProgram::class)]
    #[ORM\JoinTable(name: 'esg_reco_subsidy')]
    #[ORM\JoinColumn(name: 'recommendation_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'subsidy_program_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $subsidyPrograms;

    public function __construct()
    {
        $this->generatedAt = new \DateTimeImmutable();
        $this->subsidyPrograms = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function setPriority(int $priority): self
    {
        $this->priority = $priority;
        return $this;
    }

    public function getGapToThresholdGlobal(): float
    {
        return $this->gapToThresholdGlobal;
    }

    public function setGapToThresholdGlobal(float $gapToThresholdGlobal): self
    {
        $this->gapToThresholdGlobal = $gapToThresholdGlobal;
        return $this;
    }

    public function isEligible(): bool
    {
        return $this->isEligible;
    }

    public function setIsEligible(bool $isEligible): self
    {
        $this->isEligible = $isEligible;
        return $this;
    }

    public function getEstimatedDurationMonths(): int
    {
        return $this->estimatedDurationMonths;
    }

    public function setEstimatedDurationMonths(int $estimatedDurationMonths): self
    {
        $this->estimatedDurationMonths = $estimatedDurationMonths;
        return $this;
    }

    public function getGrossCostCad(): int
    {
        return $this->grossCostCad;
    }

    public function setGrossCostCad(int $grossCostCad): self
    {
        $this->grossCostCad = $grossCostCad;
        return $this;
    }

    public function getTotalSubsidyCad(): int
    {
        return $this->totalSubsidyCad;
    }

    public function setTotalSubsidyCad(int $totalSubsidyCad): self
    {
        $this->totalSubsidyCad = $totalSubsidyCad;
        return $this;
    }

    public function getNetCostCad(): int
    {
        return $this->netCostCad;
    }

    public function setNetCostCad(int $netCostCad): self
    {
        $this->netCostCad = $netCostCad;
        return $this;
    }

    public function getImpactNarrative(): ?string
    {
        return $this->impactNarrative;
    }

    public function setImpactNarrative(?string $impactNarrative): self
    {
        $this->impactNarrative = $impactNarrative;
        return $this;
    }

    public function getGeneratedAt(): ?\DateTimeInterface
    {
        return $this->generatedAt;
    }

    public function setGeneratedAt(\DateTimeInterface $generatedAt): self
    {
        $this->generatedAt = $generatedAt;
        return $this;
    }

    public function getSession(): ?DiagnosticSession
    {
        return $this->session;
    }

    public function setSession(?DiagnosticSession $session): self
    {
        $this->session = $session;
        return $this;
    }

    public function getReferential(): ?CertificationReferential
    {
        return $this->referential;
    }

    public function setReferential(?CertificationReferential $referential): self
    {
        $this->referential = $referential;
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
}

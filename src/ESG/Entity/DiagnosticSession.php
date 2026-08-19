<?php

namespace App\ESG\Entity;

use App\ESG\Enum\SessionStatusEnum;
use App\ESG\Repository\DiagnosticSessionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: DiagnosticSessionRepository::class)]
#[ORM\Table(name: 'esg_diag_session')]
class DiagnosticSession
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'uuid', unique: true)]
    private ?Uuid $uuid = null;

    #[ORM\Column(type: 'string', length: 50, enumType: SessionStatusEnum::class)]
    private ?SessionStatusEnum $status = SessionStatusEnum::IN_PROGRESS;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $scoreEnvironment = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $scoreGovernance = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $scoreSocial = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $scoreClimate = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $scoreGlobal = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $maturityLevel = null;

    #[ORM\Column(length: 50)]
    private ?string $referentialVersion = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $completedAt = null;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\ManyToOne(targetEntity: EsgCompany::class, inversedBy: 'diagnosticSessions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?EsgCompany $company = null;

    /**
     * @var Collection<int, DiagnosticAnswer>
     */
    #[ORM\OneToMany(mappedBy: 'session', targetEntity: DiagnosticAnswer::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $answers;

    /**
     * @var Collection<int, CertificationRecommendation>
     */
    #[ORM\OneToMany(mappedBy: 'session', targetEntity: CertificationRecommendation::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $recommendations;

    #[ORM\OneToOne(mappedBy: 'session', targetEntity: DiagnosticReport::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private ?DiagnosticReport $report = null;

    public function __construct()
    {
        $this->uuid = Uuid::v4();
        $this->createdAt = new \DateTimeImmutable();
        $this->status = SessionStatusEnum::IN_PROGRESS;
        $this->answers = new ArrayCollection();
        $this->recommendations = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): ?Uuid
    {
        return $this->uuid;
    }

    public function setUuid(Uuid $uuid): self
    {
        $this->uuid = $uuid;
        return $this;
    }

    public function getStatus(): ?SessionStatusEnum
    {
        return $this->status;
    }

    public function setStatus(SessionStatusEnum $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getScoreEnvironment(): ?float
    {
        return $this->scoreEnvironment;
    }

    public function setScoreEnvironment(?float $scoreEnvironment): self
    {
        $this->scoreEnvironment = $scoreEnvironment;
        return $this;
    }

    public function getScoreGovernance(): ?float
    {
        return $this->scoreGovernance;
    }

    public function setScoreGovernance(?float $scoreGovernance): self
    {
        $this->scoreGovernance = $scoreGovernance;
        return $this;
    }

    public function getScoreSocial(): ?float
    {
        return $this->scoreSocial;
    }

    public function setScoreSocial(?float $scoreSocial): self
    {
        $this->scoreSocial = $scoreSocial;
        return $this;
    }

    public function getScoreClimate(): ?float
    {
        return $this->scoreClimate;
    }

    public function setScoreClimate(?float $scoreClimate): self
    {
        $this->scoreClimate = $scoreClimate;
        return $this;
    }

    public function getScoreGlobal(): ?float
    {
        return $this->scoreGlobal;
    }

    public function setScoreGlobal(?float $scoreGlobal): self
    {
        $this->scoreGlobal = $scoreGlobal;
        return $this;
    }

    public function getMaturityLevel(): ?string
    {
        return $this->maturityLevel;
    }

    public function setMaturityLevel(?string $maturityLevel): self
    {
        $this->maturityLevel = $maturityLevel;
        return $this;
    }

    public function getReferentialVersion(): ?string
    {
        return $this->referentialVersion;
    }

    public function setReferentialVersion(string $referentialVersion): self
    {
        $this->referentialVersion = $referentialVersion;
        return $this;
    }

    public function getCompletedAt(): ?\DateTimeInterface
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?\DateTimeInterface $completedAt): self
    {
        $this->completedAt = $completedAt;
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

    public function getCompany(): ?EsgCompany
    {
        return $this->company;
    }

    public function setCompany(?EsgCompany $company): self
    {
        $this->company = $company;
        return $this;
    }

    /**
     * @return Collection<int, DiagnosticAnswer>
     */
    public function getAnswers(): Collection
    {
        return $this->answers;
    }

    public function addAnswer(DiagnosticAnswer $answer): self
    {
        if (!$this->answers->contains($answer)) {
            $this->answers->add($answer);
            $answer->setSession($this);
        }
        return $this;
    }

    public function removeAnswer(DiagnosticAnswer $answer): self
    {
        if ($this->answers->removeElement($answer)) {
            if ($answer->getSession() === $this) {
                $answer->setSession(null);
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

    public function addRecommendation(CertificationRecommendation $recommendation): self
    {
        if (!$this->recommendations->contains($recommendation)) {
            $this->recommendations->add($recommendation);
            $recommendation->setSession($this);
        }
        return $this;
    }

    public function removeRecommendation(CertificationRecommendation $recommendation): self
    {
        if ($this->recommendations->removeElement($recommendation)) {
            if ($recommendation->getSession() === $this) {
                $recommendation->setSession(null);
            }
        }
        return $this;
    }

    public function getReport(): ?DiagnosticReport
    {
        return $this->report;
    }

    public function setReport(?DiagnosticReport $report): self
    {
        // set the owning side of the relation if necessary
        if ($report !== null && $report->getSession() !== $this) {
            $report->setSession($this);
        }
        $this->report = $report;
        return $this;
    }
}

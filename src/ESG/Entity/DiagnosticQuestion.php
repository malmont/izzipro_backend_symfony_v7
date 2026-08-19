<?php

namespace App\ESG\Entity;

use App\ESG\Enum\AnswerTypeEnum;
use App\ESG\Enum\DomainEnum;
use App\ESG\Repository\DiagnosticQuestionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DiagnosticQuestionRepository::class)]
#[ORM\Table(name: 'esg_diag_question')]
#[ORM\Index(columns: ['domain', 'display_order'], name: 'idx_question_domain_order')]
class DiagnosticQuestion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 50, enumType: DomainEnum::class)]
    private ?DomainEnum $domain = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $questionText = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $helpText = null;

    #[ORM\Column(type: 'integer')]
    private int $weight = 1;

    #[ORM\Column(type: 'string', length: 50, enumType: AnswerTypeEnum::class)]
    private ?AnswerTypeEnum $answerType = null;

    #[ORM\Column(name: 'display_order', type: 'integer')]
    private int $displayOrder = 0;

    #[ORM\Column(type: 'boolean')]
    private bool $isActive = true;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDomain(): ?DomainEnum
    {
        return $this->domain;
    }

    public function setDomain(DomainEnum $domain): self
    {
        $this->domain = $domain;
        return $this;
    }

    public function getQuestionText(): ?string
    {
        return $this->questionText;
    }

    public function setQuestionText(string $questionText): self
    {
        $this->questionText = $questionText;
        return $this;
    }

    public function getHelpText(): ?string
    {
        return $this->helpText;
    }

    public function setHelpText(?string $helpText): self
    {
        $this->helpText = $helpText;
        return $this;
    }

    public function getWeight(): int
    {
        return $this->weight;
    }

    public function setWeight(int $weight): self
    {
        $this->weight = $weight;
        return $this;
    }

    public function getAnswerType(): ?AnswerTypeEnum
    {
        return $this->answerType;
    }

    public function setAnswerType(AnswerTypeEnum $answerType): self
    {
        $this->answerType = $answerType;
        return $this;
    }

    public function getDisplayOrder(): int
    {
        return $this->displayOrder;
    }

    public function setDisplayOrder(int $displayOrder): self
    {
        $this->displayOrder = $displayOrder;
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

    public function setCreatedAt(\DateTimeInterface $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }
}

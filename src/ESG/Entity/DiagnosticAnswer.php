<?php

namespace App\ESG\Entity;

use App\ESG\Repository\DiagnosticAnswerRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DiagnosticAnswerRepository::class)]
#[ORM\Table(name: 'esg_diag_answer')]
#[ORM\UniqueConstraint(name: 'uniq_session_question', columns: ['session_id', 'question_id'])]
class DiagnosticAnswer
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'integer')]
    private int $answerValue = 0;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $answeredAt = null;

    #[ORM\ManyToOne(targetEntity: DiagnosticSession::class, inversedBy: 'answers')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?DiagnosticSession $session = null;

    #[ORM\ManyToOne(targetEntity: DiagnosticQuestion::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?DiagnosticQuestion $question = null;

    public function __construct()
    {
        $this->answeredAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAnswerValue(): int
    {
        return $this->answerValue;
    }

    public function setAnswerValue(int $answerValue): self
    {
        $this->answerValue = $answerValue;
        return $this;
    }

    public function getAnsweredAt(): ?\DateTimeInterface
    {
        return $this->answeredAt;
    }

    public function setAnsweredAt(\DateTimeInterface $answeredAt): self
    {
        $this->answeredAt = $answeredAt;
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

    public function getQuestion(): ?DiagnosticQuestion
    {
        return $this->question;
    }

    public function setQuestion(?DiagnosticQuestion $question): self
    {
        $this->question = $question;
        return $this;
    }
}

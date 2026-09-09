<?php

namespace App\MemoiresVivantes\Entity;

use App\MemoiresVivantes\Repository\MemoireQuestionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MemoireQuestionRepository::class)]
#[ORM\Table(name: 'mv_question')]
#[ORM\Index(columns: ['theme', 'display_order'], name: 'idx_mv_question_theme_order')]
#[ORM\Index(columns: ['book_type'], name: 'idx_mv_question_book_type')]
class MemoireQuestion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'book_type', length: 50)]
    private string $bookType = 'individuel'; // individuel, couple, famille

    #[ORM\Column(length: 100)]
    private string $theme; // enfance, adulte, sagesse, avant_nous_1, la_rencontre, etc.

    #[ORM\Column(type: Types::TEXT)]
    private string $questionText;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $tip = null;

    #[ORM\Column(name: 'display_order', type: 'integer')]
    private int $displayOrder = 0;

    #[ORM\Column(type: 'boolean')]
    private bool $isActive = true;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBookType(): string
    {
        return $this->bookType;
    }

    public function setBookType(string $bookType): self
    {
        $this->bookType = $bookType;
        return $this;
    }

    public function getTheme(): string
    {
        return $this->theme;
    }

    public function setTheme(string $theme): self
    {
        $this->theme = $theme;
        return $this;
    }

    public function getQuestionText(): string
    {
        return $this->questionText;
    }

    public function setQuestionText(string $questionText): self
    {
        $this->questionText = $questionText;
        return $this;
    }

    public function getTip(): ?string
    {
        return $this->tip;
    }

    public function setTip(?string $tip): self
    {
        $this->tip = $tip;
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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeInterface $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    /**
     * Format exportable pour le frontend Next.js
     */
    public function toFrontArray(): array
    {
        return [
            'id' => $this->id,
            'index' => $this->displayOrder,
            'question' => $this->questionText,
            'tip' => $this->tip,
        ];
    }
}

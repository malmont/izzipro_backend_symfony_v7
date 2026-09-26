<?php

namespace App\MemoiresVivantes\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Chapitre d'un type de livre. Le code correspond à mv_chapter.theme et mv_question.theme.
 */
#[ORM\Entity]
#[ORM\Table(name: 'mv_book_type_chapter')]
#[ORM\UniqueConstraint(name: 'uniq_mv_book_type_chapter_code', columns: ['book_type_id', 'code'])]
#[ORM\HasLifecycleCallbacks]
class BookTypeChapter
{
    /** Récit direct : seul l'interlocuteur 1 s'exprime */
    public const SPEAKER_PERSON1 = 'person1';
    /** Récit direct : seul l'interlocuteur 2 s'exprime */
    public const SPEAKER_PERSON2 = 'person2';
    /** Récit direct : les deux interlocuteurs s'expriment */
    public const SPEAKER_BOTH = 'both';
    /** Récit collectif : témoignages des contributeurs (verbatim ou hybride) */
    public const SPEAKER_CONTRIBUTORS = 'contributors';
    /** Récit collectif : synthèse de tous les témoignages du livre (nécessite des témoignages) */
    public const SPEAKER_SYNTHESIS = 'synthesis';

    public const SPEAKERS_DIRECT = [self::SPEAKER_PERSON1, self::SPEAKER_PERSON2, self::SPEAKER_BOTH];
    public const SPEAKERS_COLLECTIVE = [self::SPEAKER_CONTRIBUTORS, self::SPEAKER_SYNTHESIS];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: BookType::class, inversedBy: 'chapters')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?BookType $bookType = null;

    #[ORM\Column(length: 100)]
    private string $code;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(type: 'integer')]
    private int $position = 0;

    #[ORM\Column(length: 20)]
    private string $speaker = self::SPEAKER_PERSON1;

    /** Consigne propre au chapitre, ajoutée à celle du type */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $promptRaw = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $promptOptimized = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBookType(): ?BookType
    {
        return $this->bookType;
    }

    public function setBookType(?BookType $bookType): self
    {
        $this->bookType = $bookType;
        return $this;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): self
    {
        $this->code = $code;
        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;
        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): self
    {
        $this->position = $position;
        return $this;
    }

    public function getSpeaker(): string
    {
        return $this->speaker;
    }

    public function setSpeaker(string $speaker): self
    {
        if (!in_array($speaker, [...self::SPEAKERS_DIRECT, ...self::SPEAKERS_COLLECTIVE], true)) {
            throw new \InvalidArgumentException("Interlocuteur de chapitre inconnu : {$speaker}");
        }
        $this->speaker = $speaker;
        return $this;
    }

    public function isSynthesis(): bool
    {
        return $this->speaker === self::SPEAKER_SYNTHESIS;
    }

    public function getPromptRaw(): ?string
    {
        return $this->promptRaw;
    }

    public function setPromptRaw(?string $promptRaw): self
    {
        $this->promptRaw = $promptRaw;
        return $this;
    }

    public function getPromptOptimized(): ?string
    {
        return $this->promptOptimized;
    }

    public function setPromptOptimized(?string $promptOptimized): self
    {
        $this->promptOptimized = $promptOptimized;
        return $this;
    }

    public function getEffectivePrompt(): ?string
    {
        return $this->promptOptimized ?: $this->promptRaw;
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
}

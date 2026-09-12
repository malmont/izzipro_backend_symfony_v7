<?php

namespace App\MemoiresVivantes\Entity;

use App\MemoiresVivantes\Repository\ChapterRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ChapterRepository::class)]
#[ORM\Table(name: 'mv_chapter')]
#[ORM\HasLifecycleCallbacks]
class Chapter
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: \App\MemoiresVivantes\Entity\Book::class, inversedBy: 'chapters')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Book $book = null;

    #[ORM\Column(length: 255)]
    private ?string $theme = null;

    #[ORM\Column(length: 255)]
    private ?string $title = null;

    #[ORM\Column]
    private ?int $position = null;

    #[ORM\Column(type: Types::JSON)]
    private array $answers = [];

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $contributorAnswers = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $contentPart1 = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $contentPart2 = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $contentGenerated = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $contentFinal = null;

    #[ORM\Column(length: 50)]
    private ?string $generationStatus = 'pending';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $generationError = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $updatedAt = null;

    #[ORM\OneToMany(mappedBy: 'chapter', targetEntity: ChapterPhoto::class, cascade: ['remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['sortOrder' => 'ASC'])]
    private Collection $photos;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $photoLayout = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTime();
        $this->photos = new ArrayCollection();
    }

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function updateTimestamps(): void
    {
        $this->updatedAt = new \DateTime();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getBook(): ?Book
    {
        return $this->book;
    }

    public function setBook(?Book $book): self
    {
        $this->book = $book;
        return $this;
    }

    public function getTheme(): ?string
    {
        return $this->theme;
    }

    public function setTheme(string $theme): self
    {
        $this->theme = $theme;
        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;
        return $this;
    }

    public function getPosition(): ?int
    {
        return $this->position;
    }

    public function setPosition(int $position): self
    {
        $this->position = $position;
        return $this;
    }

    public function getAnswers(): array
    {
        return $this->answers;
    }

    public function setAnswers(array $answers): self
    {
        $this->answers = $answers;
        return $this;
    }

    public function getContentPart1(): ?string
    {
        return $this->contentPart1;
    }

    public function setContentPart1(?string $contentPart1): self
    {
        $this->contentPart1 = $contentPart1;
        return $this;
    }

    public function getContentPart2(): ?string
    {
        return $this->contentPart2;
    }

    public function setContentPart2(?string $contentPart2): self
    {
        $this->contentPart2 = $contentPart2;
        return $this;
    }

    public function getContentGenerated(): ?string
    {
        return $this->contentGenerated;
    }

    public function setContentGenerated(?string $contentGenerated): self
    {
        $this->contentGenerated = $contentGenerated;
        return $this;
    }

    public function getContentFinal(): ?string
    {
        return $this->contentFinal;
    }

    public function setContentFinal(?string $contentFinal): self
    {
        $this->contentFinal = $contentFinal;
        return $this;
    }

    public function getGenerationStatus(): ?string
    {
        return $this->generationStatus;
    }

    public function setGenerationStatus(string $generationStatus): self
    {
        $this->generationStatus = $generationStatus;
        return $this;
    }

    public function getGenerationError(): ?string
    {
        return $this->generationError;
    }

    public function setGenerationError(?string $generationError): self
    {
        $this->generationError = $generationError;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }

    /**
     * @return Collection<int, ChapterPhoto>
     */
    public function getPhotos(): Collection
    {
        return $this->photos;
    }

    public function addPhoto($photo): self
    {
        if (!$this->photos->contains($photo)) {
            $this->photos->add($photo);
            $photo->setChapter($this);
        }
        return $this;
    }

    public function removePhoto($photo): self
    {
        if ($this->photos->removeElement($photo)) {
            if ($photo->getChapter() === $this) {
                $photo->setChapter(null);
            }
        }
        return $this;
    }

    public function getContributorAnswers(): ?array
    {
        return $this->contributorAnswers;
    }

    public function setContributorAnswers(?array $contributorAnswers): self
    {
        $this->contributorAnswers = $contributorAnswers;
        return $this;
    }

    public function getPhotoLayout(): ?array
    {
        return $this->photoLayout;
    }

    public function setPhotoLayout(?array $photoLayout): self
    {
        $this->photoLayout = $photoLayout;
        return $this;
    }
}

<?php
 
namespace App\MemoiresVivantes\Entity;
 
use ApiPlatform\Metadata\ApiResource;
use App\MemoiresVivantes\Repository\BookRepository;
use App\Entity\User;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;
 
#[ORM\Entity(repositoryClass: BookRepository::class)]
#[ORM\Table(name: 'mv_book')]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    normalizationContext: ['groups' => ['book:read']],
    denormalizationContext: ['groups' => ['book:write']]
)]
class Book
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[Groups(['book:read', 'chapter:read'])]
    private ?Uuid $id = null;
 
    #[ORM\ManyToOne(targetEntity: \App\Entity\User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;
 
    #[ORM\Column(length: 255)]
    #[Groups(['book:read', 'book:write', 'chapter:read'])]
    private ?string $title = null;
 
    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['book:read', 'book:write', 'chapter:read'])]
    private ?string $subtitle = null;
 
    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['book:read', 'book:write', 'chapter:read'])]
    private ?string $birthplace = null;
 
    #[ORM\Column(length: 50)]
    #[Groups(['book:read', 'book:write', 'chapter:read'])]
    private string $type = 'individuel'; // individuel, couple, famille
 
    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['book:read', 'book:write', 'chapter:read'])]
    private ?string $person1FirstName = null;
 
    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['book:read', 'book:write', 'chapter:read'])]
    private ?string $person1Birthplace = null;
 
    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['book:read', 'book:write', 'chapter:read'])]
    private ?string $person2FirstName = null;
 
    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['book:read', 'book:write', 'chapter:read'])]
    private ?string $person2Birthplace = null;
 
    #[ORM\Column(length: 20)]
    #[Groups(['book:read', 'book:write', 'chapter:read'])]
    private ?string $format = 'livre_s'; // livre_s, livre_l, magazine
 
    #[ORM\Column(length: 20)]
    #[Groups(['book:read', 'book:write', 'chapter:read'])]
    private ?string $status = 'draft'; // draft, generating, ready, paid
 
    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['book:read', 'chapter:read'])]
    private ?string $coverPhotoPath = null;
 
    #[ORM\Column]
    #[Groups(['book:read'])]
    private ?\DateTimeImmutable $createdAt = null;
 
    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    #[Groups(['book:read'])]
    private ?\DateTimeInterface $updatedAt = null;
 
    #[ORM\OneToMany(mappedBy: 'book', targetEntity: \App\MemoiresVivantes\Entity\Chapter::class, cascade: ['remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    #[Groups(['book:read'])]
    private Collection $chapters;
 
    #[ORM\OneToMany(mappedBy: 'book', targetEntity: \App\MemoiresVivantes\Entity\Contributor::class, cascade: ['remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['sortOrder' => 'ASC'])]
    #[Groups(['book:read'])]
    private Collection $contributors;
 
    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTime();
        $this->chapters = new ArrayCollection();
        $this->contributors = new ArrayCollection();
    }
 
    public function getId(): ?Uuid
    {
        return $this->id;
    }
 
    public function getUser(): ?User
    {
        return $this->user;
    }
 
    public function setUser(?User $user): static
    {
        $this->user = $user;
        return $this;
    }
 
    public function getTitle(): ?string
    {
        return $this->title;
    }
 
    public function setTitle(string $title): static
    {
        $this->title = $title;
        return $this;
    }
 
    public function getSubtitle(): ?string
    {
        return $this->subtitle;
    }
 
    public function setSubtitle(?string $subtitle): static
    {
        $this->subtitle = $subtitle;
        return $this;
    }
 
    public function getBirthplace(): ?string
    {
        return $this->birthplace;
    }
 
    public function setBirthplace(?string $birthplace): static
    {
        $this->birthplace = $birthplace;
        return $this;
    }
 
    public function getType(): string
    {
        return $this->type;
    }
 
    public function setType(string $type): static
    {
        $this->type = $type;
        return $this;
    }
 
    public function getPerson1FirstName(): ?string
    {
        return $this->person1FirstName;
    }
 
    public function setPerson1FirstName(?string $person1FirstName): static
    {
        $this->person1FirstName = $person1FirstName;
        return $this;
    }
 
    public function getPerson1Birthplace(): ?string
    {
        return $this->person1Birthplace;
    }
 
    public function setPerson1Birthplace(?string $person1Birthplace): static
    {
        $this->person1Birthplace = $person1Birthplace;
        return $this;
    }
 
    public function getPerson2FirstName(): ?string
    {
        return $this->person2FirstName;
    }
 
    public function setPerson2FirstName(?string $person2FirstName): static
    {
        $this->person2FirstName = $person2FirstName;
        return $this;
    }
 
    public function getPerson2Birthplace(): ?string
    {
        return $this->person2Birthplace;
    }
 
    public function setPerson2Birthplace(?string $person2Birthplace): static
    {
        $this->person2Birthplace = $person2Birthplace;
        return $this;
    }
 
    public function getFormat(): ?string
    {
        return $this->format;
    }
 
    public function setFormat(string $format): static
    {
        $this->format = $format;
        return $this;
    }
 
    public function getStatus(): ?string
    {
        return $this->status;
    }
 
    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }
 
    public function getCoverPhotoPath(): ?string
    {
        return $this->coverPhotoPath;
    }
 
    public function setCoverPhotoPath(?string $coverPhotoPath): static
    {
        $this->coverPhotoPath = $coverPhotoPath;
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
 
    #[ORM\PreUpdate]
    public function setUpdatedAtValue(): void
    {
        $this->updatedAt = new \DateTime();
    }
 
    public function getChapters(): Collection
    {
        return $this->chapters;
    }
 
    public function addChapter(Chapter $chapter): static
    {
        if (!$this->chapters->contains($chapter)) {
            $this->chapters->add($chapter);
            $chapter->setBook($this);
        }
        return $this;
    }
 
    public function removeChapter(Chapter $chapter): static
    {
        if ($this->chapters->removeElement($chapter)) {
            if ($chapter->getBook() === $this) {
                $chapter->setBook(null);
            }
        }
        return $this;
    }
 
    public function getContributors(): Collection
    {
        return $this->contributors;
    }
 
    public function addContributor(Contributor $contributor): static
    {
        if (!$this->contributors->contains($contributor)) {
            $this->contributors->add($contributor);
            $contributor->setBook($this);
        }
        return $this;
    }
 
    public function removeContributor(Contributor $contributor): static
    {
        if ($this->contributors->removeElement($contributor)) {
            if ($contributor->getBook() === $this) {
                $contributor->setBook(null);
            }
        }
        return $this;
    }
}

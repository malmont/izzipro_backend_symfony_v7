<?php

namespace App\MemoiresVivantes\Entity;

use App\MemoiresVivantes\Repository\BookTypeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Type de livre configurable depuis l'admin.
 *
 * Le lien avec les livres et les questions se fait par le code (mv_book.type, mv_question.book_type),
 * le code est donc immuable une fois le type créé.
 */
#[ORM\Entity(repositoryClass: BookTypeRepository::class)]
#[ORM\Table(name: 'mv_book_type')]
#[ORM\HasLifecycleCallbacks]
class BookType
{
    /** Récit direct : 1 ou 2 interlocuteurs répondent eux-mêmes (individuel, couple...) */
    public const FAMILY_DIRECT = 'direct';
    /** Récit collectif : plusieurs contributeurs déclarés via le front, avec un rôle (famille, hommage...) */
    public const FAMILY_COLLECTIVE = 'collectif';

    /** La génération utilise les consignes écrites en dur dans AnthropicService (types historiques) */
    public const PROMPT_SOURCE_CODE = 'code';
    /** La génération utilise les consignes saisies en base */
    public const PROMPT_SOURCE_DATABASE = 'database';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50, unique: true)]
    private string $code;

    #[ORM\Column(length: 255)]
    private string $label;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 20)]
    private string $family = self::FAMILY_DIRECT;

    /** Nombre d'interlocuteurs pour la famille "direct" (1 ou 2), null pour "collectif" */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $speakerCount = 1;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $speaker1Label = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $speaker2Label = null;

    /** Famille "collectif" : les sujets du livre peuvent ne pas participer (ex. parents décédés) */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $subjectsMayBeAbsent = false;

    /** Rôle utilisé quand aucun contributeur n'est identifié */
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $defaultRole = null;

    /** Rôle utilisé quand aucun contributeur n'est identifié et que les sujets ne participent pas */
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $defaultRoleWhenSubjectsAbsent = null;

    /** Consigne générale saisie par l'admin */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $promptRaw = null;

    /** Consigne générale optimisée et validée (utilisée en priorité) */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $promptOptimized = null;

    #[ORM\Column(length: 20, options: ['default' => self::PROMPT_SOURCE_DATABASE])]
    private string $promptSource = self::PROMPT_SOURCE_DATABASE;

    /** Type historique (individuel, couple, famille, hommage) : non supprimable */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isSystem = false;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $displayOrder = 0;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

    #[ORM\OneToMany(mappedBy: 'bookType', targetEntity: BookTypeChapter::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $chapters;

    #[ORM\OneToMany(mappedBy: 'bookType', targetEntity: BookTypeRole::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['displayOrder' => 'ASC'])]
    private Collection $roles;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->chapters = new ArrayCollection();
        $this->roles = new ArrayCollection();
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

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): self
    {
        $this->code = $code;
        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): self
    {
        $this->label = $label;
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

    public function getFamily(): string
    {
        return $this->family;
    }

    public function setFamily(string $family): self
    {
        if (!in_array($family, [self::FAMILY_DIRECT, self::FAMILY_COLLECTIVE], true)) {
            throw new \InvalidArgumentException("Famille de livre inconnue : {$family}");
        }
        $this->family = $family;
        return $this;
    }

    public function isDirect(): bool
    {
        return $this->family === self::FAMILY_DIRECT;
    }

    public function isCollective(): bool
    {
        return $this->family === self::FAMILY_COLLECTIVE;
    }

    public function getSpeakerCount(): ?int
    {
        return $this->speakerCount;
    }

    public function setSpeakerCount(?int $speakerCount): self
    {
        if ($speakerCount !== null && !in_array($speakerCount, [1, 2], true)) {
            throw new \InvalidArgumentException('Un récit direct a 1 ou 2 interlocuteurs.');
        }
        $this->speakerCount = $speakerCount;
        return $this;
    }

    public function getSpeaker1Label(): ?string
    {
        return $this->speaker1Label;
    }

    public function setSpeaker1Label(?string $speaker1Label): self
    {
        $this->speaker1Label = $speaker1Label;
        return $this;
    }

    public function getSpeaker2Label(): ?string
    {
        return $this->speaker2Label;
    }

    public function setSpeaker2Label(?string $speaker2Label): self
    {
        $this->speaker2Label = $speaker2Label;
        return $this;
    }

    public function isSubjectsMayBeAbsent(): bool
    {
        return $this->subjectsMayBeAbsent;
    }

    public function setSubjectsMayBeAbsent(bool $subjectsMayBeAbsent): self
    {
        $this->subjectsMayBeAbsent = $subjectsMayBeAbsent;
        return $this;
    }

    public function getDefaultRole(): ?string
    {
        return $this->defaultRole;
    }

    public function setDefaultRole(?string $defaultRole): self
    {
        $this->defaultRole = $defaultRole;
        return $this;
    }

    public function getDefaultRoleWhenSubjectsAbsent(): ?string
    {
        return $this->defaultRoleWhenSubjectsAbsent;
    }

    public function setDefaultRoleWhenSubjectsAbsent(?string $defaultRoleWhenSubjectsAbsent): self
    {
        $this->defaultRoleWhenSubjectsAbsent = $defaultRoleWhenSubjectsAbsent;
        return $this;
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

    /** Consigne effectivement utilisée : la version optimisée validée, sinon la brute */
    public function getEffectivePrompt(): ?string
    {
        return $this->promptOptimized ?: $this->promptRaw;
    }

    public function getPromptSource(): string
    {
        return $this->promptSource;
    }

    public function setPromptSource(string $promptSource): self
    {
        if (!in_array($promptSource, [self::PROMPT_SOURCE_CODE, self::PROMPT_SOURCE_DATABASE], true)) {
            throw new \InvalidArgumentException("Source de consigne inconnue : {$promptSource}");
        }
        $this->promptSource = $promptSource;
        return $this;
    }

    public function isSystem(): bool
    {
        return $this->isSystem;
    }

    public function setIsSystem(bool $isSystem): self
    {
        $this->isSystem = $isSystem;
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

    public function getDisplayOrder(): int
    {
        return $this->displayOrder;
    }

    public function setDisplayOrder(int $displayOrder): self
    {
        $this->displayOrder = $displayOrder;
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

    /**
     * @return Collection<int, BookTypeChapter>
     */
    public function getChapters(): Collection
    {
        return $this->chapters;
    }

    public function getChapter(string $code): ?BookTypeChapter
    {
        foreach ($this->chapters as $chapter) {
            if ($chapter->getCode() === $code) {
                return $chapter;
            }
        }
        return null;
    }

    public function addChapter(BookTypeChapter $chapter): self
    {
        if (!$this->chapters->contains($chapter)) {
            $this->chapters->add($chapter);
            $chapter->setBookType($this);
        }
        return $this;
    }

    public function removeChapter(BookTypeChapter $chapter): self
    {
        $this->chapters->removeElement($chapter);
        return $this;
    }

    /**
     * @return Collection<int, BookTypeRole>
     */
    public function getRoles(): Collection
    {
        return $this->roles;
    }

    public function getRole(string $code): ?BookTypeRole
    {
        foreach ($this->roles as $role) {
            if ($role->getCode() === $code) {
                return $role;
            }
        }
        return null;
    }

    public function addRole(BookTypeRole $role): self
    {
        if (!$this->roles->contains($role)) {
            $this->roles->add($role);
            $role->setBookType($this);
        }
        return $this;
    }

    public function removeRole(BookTypeRole $role): self
    {
        $this->roles->removeElement($role);
        return $this;
    }
}

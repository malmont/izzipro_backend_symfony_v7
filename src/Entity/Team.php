<?php

namespace App\Entity;

use App\Repository\TeamRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Entity\TranslatableInterface;

#[ORM\Entity(repositoryClass: TeamRepository::class)]
class Team implements TranslatableInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $image = null;

    #[ORM\Column(length: 255)]
    private ?string $role = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(nullable: true)]
    private ?int $gemsuiteTeamId = null;

    /**
     * @var Collection<int, TeamTranslation>
     */
    #[ORM\OneToMany(
        mappedBy: 'team',
        targetEntity: TeamTranslation::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
        fetch: 'EXTRA_LAZY'
    )]
    private Collection $translations;

    public function __construct()
    {
        $this->translations = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getImage(): ?string
    {
        return $this->image;
    }

    public function setImage(?string $image): static
    {
        $this->image = $image;

        return $this;
    }

    public function getRole(): ?string
    {
        return $this->role;
    }

    public function setRole(string $role): static
    {
        $this->role = $role;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getGemsuiteTeamId(): ?int
    {
        return $this->gemsuiteTeamId;
    }

    public function setGemsuiteTeamId(?int $gemsuiteTeamId): static
    {
        $this->gemsuiteTeamId = $gemsuiteTeamId;

        return $this;
    }

    /**
     * @return Collection<int, TeamTranslation>
     */
    public function getTranslations(): Collection
    {
        return $this->translations;
    }

    public function addTranslation(object $translation): void
    {
        if (!$this->translations->contains($translation)) {
            $this->translations->add($translation);
            $translation->setTeam($this);
        }
    }

    public function removeTranslation(TeamTranslation $translation): static
    {
        if ($this->translations->removeElement($translation)) {
            if ($translation->getTeam() === $this) {
                $translation->setTeam(null);
            }
        }

        return $this;
    }

    public function getTranslation(string $locale): ?TeamTranslation
    {
        foreach ($this->translations as $translation) {
            if ($translation->getLanguage() === $locale) {
                return $translation;
            }
        }

        foreach ($this->translations as $translation) {
            if ($translation->getLanguage() === 'fr') {
                return $translation;
            }
        }

        return $this->translations->first() ?: null;
    }

    public function getTranslatableFields(): array
    {
        return ['role', 'description'];
    }

    public function getTranslationEntityClass(): string
    {
        return TeamTranslation::class;
    }

    public function findTranslationByLocale(string $locale): ?object
    {
        foreach ($this->translations as $translation) {
            if ($translation->getLanguage() === $locale) {
                return $translation;
            }
        }
        return null;
    }
}

<?php

namespace App\Entity;

use App\Repository\PresentationGroupRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use App\Entity\TranslatableInterface;

#[ORM\Entity(repositoryClass: PresentationGroupRepository::class)]
class PresentationGroup implements TranslatableInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $titre = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $texte = null;

    /**
     * @var Collection<int, Presentation>
     */
    #[ORM\ManyToMany(targetEntity: Presentation::class, inversedBy: 'presentationGroups')]
    private Collection $presentations;

    /**
     * @var Collection<int, PresentationGroupTranslation>
     */
    #[ORM\OneToMany(
    mappedBy: 'presentationGroup', 
    targetEntity: PresentationGroupTranslation::class, 
    cascade: ['persist', 'remove'], 
    orphanRemoval: true,
    fetch: 'EXTRA_LAZY'
    )]
    private Collection $translations;

    /**
     * Ordre d'affichage des présentations choisi depuis l'éditeur des landing pages : liste JSON d'identifiants.
     * null : ordre de la base. Une présentation absente de la liste vient après, par identifiant.
     */
    #[ORM\Column(name: 'presentation_order', type: \Doctrine\DBAL\Types\Types::TEXT, nullable: true)]
    private ?string $presentationOrder = null;

    public function __construct()
    {
        $this->presentations = new ArrayCollection();
        $this->translations = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitre(): ?string
    {
        return $this->titre;
    }

    public function setTitre(string $titre): static
    {
        $this->titre = $titre;

        return $this;
    }

    /**
     * @return Collection<int, Presentation>
     */
    public function getPresentations(): Collection
    {
        return $this->presentations;
    }

    /** @return list<Presentation> dans l'ordre choisi (getPresentationOrder) */
    public function getOrderedPresentations(): array
    {
        $order = array_flip($this->getPresentationOrder());
        $presentations = $this->presentations->toArray();
        usort($presentations, fn (Presentation $a, Presentation $b) => [$order[$a->getId()] ?? PHP_INT_MAX, $a->getId()] <=> [$order[$b->getId()] ?? PHP_INT_MAX, $b->getId()]);

        return array_values($presentations);
    }

    /** @return list<int> */
    public function getPresentationOrder(): array
    {
        $order = $this->presentationOrder !== null ? json_decode($this->presentationOrder, true) : null;

        return is_array($order) ? array_values(array_filter($order, 'is_int')) : [];
    }

    /** @param list<int>|null $order */
    public function setPresentationOrder(?array $order): static
    {
        $this->presentationOrder = $order === null ? null : json_encode(array_values($order));

        return $this;
    }

    public function addPresentation(Presentation $presentation): static
    {
        if (!$this->presentations->contains($presentation)) {
            $this->presentations->add($presentation);
        }

        return $this;
    }

    public function removePresentation(Presentation $presentation): static
    {
        $this->presentations->removeElement($presentation);

        return $this;
    }

    /**
     * @return Collection<int, PresentationGroupTranslation>
     */
    public function getTranslations(): Collection
    {
        return $this->translations;
    }

    public function addTranslation(object $translation): void
    {
        if (!$this->translations->contains($translation)) {
            $this->translations->add($translation);
            $translation->setPresentationGroup($this);
        }
    }

    public function removeTranslation(PresentationGroupTranslation $translation): static
    {
        if ($this->translations->removeElement($translation)) {
            // set the owning side to null (unless already changed)
            if ($translation->getPresentationGroup() === $this) {
                $translation->setPresentationGroup(null);
            }
        }

        return $this;
    }

     public function getTranslation(string $locale): ?PresentationGroupTranslation
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
        return ['titre', 'texte'];
    }

    public function getTexte(): ?string
    {
        return $this->texte;
    }

    public function setTexte(?string $texte): self
    {
        $this->texte = $texte;
        return $this;
    }

    public function getTranslationEntityClass(): string
    {
        return PresentationGroupTranslation::class;
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

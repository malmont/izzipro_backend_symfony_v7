<?php

namespace App\Entity;

use App\Repository\ServiceFamilyRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Famille de prestations (28/10/2026) : regroupe les ServiceOffer dans les formulaires à étapes (optionsGroupBy
 * « family »). Nom de base en français, traductions par langue ; ordre d'affichage par position.
 */
#[ORM\Entity(repositoryClass: ServiceFamilyRepository::class)]
#[ORM\Table(name: 'service_family')]
class ServiceFamily
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $name = '';

    #[ORM\Column]
    private int $position = 0;

    /** @var Collection<int, ServiceFamilyTranslation> */
    #[ORM\OneToMany(mappedBy: 'family', targetEntity: ServiceFamilyTranslation::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $translations;

    public function __construct()
    {
        $this->translations = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }
    public function getPosition(): int { return $this->position; }
    public function setPosition(int $position): static { $this->position = $position; return $this; }

    /** @return Collection<int, ServiceFamilyTranslation> */
    public function getTranslations(): Collection { return $this->translations; }

    public function addTranslation(ServiceFamilyTranslation $translation): static
    {
        if (!$this->translations->contains($translation)) {
            $this->translations->add($translation);
            $translation->setFamily($this);
        }

        return $this;
    }

    public function removeTranslation(ServiceFamilyTranslation $translation): static
    {
        $this->translations->removeElement($translation);

        return $this;
    }

    /** Nom dans la langue demandée, sinon le nom de base */
    public function nameFor(string $locale): string
    {
        foreach ($this->translations as $translation) {
            if ($translation->getLanguage() === $locale && trim($translation->getName()) !== '') {
                return $translation->getName();
            }
        }

        return $this->name;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}

<?php

namespace App\Entity;

use App\Entity\Traits\DemoContentTrait;
use App\Repository\ServiceOfferRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\DBAL\Types\Types;
use App\Entity\TranslatableInterface;


#[ORM\Entity(repositoryClass: ServiceOfferRepository::class)]
class ServiceOffer implements TranslatableInterface, DemoContentInterface
{
    use DemoContentTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $titre = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $logo = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $titreCommentaire = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)] 
    private ?string $descriptions = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photoService = null;

    /** Famille de la prestation (formulaires à étapes, optionsGroupBy « family », 28/10/2026) */
    #[ORM\ManyToOne(targetEntity: ServiceFamily::class)]
    #[ORM\JoinColumn(name: 'family_id', nullable: true, onDelete: 'SET NULL')]
    private ?ServiceFamily $family = null;

    /** Ordre d'affichage (croissant ; à égalité, l'identifiant) */
    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

    /** Proposée dans les formulaires de réservation ; vrai par défaut (prestations existantes comprises) */
    #[ORM\Column(options: ['default' => true])]
    private bool $bookable = true;

    #[ORM\Column(nullable: true)]
    private ?int $durationMinutes = null;

    /**
     * @var Collection<int, ServiceOfferTranslation>
     */
    #[ORM\OneToMany(
    mappedBy: 'serviceOffer', 
    targetEntity: ServiceOfferTranslation::class, 
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

    public function getFamily(): ?ServiceFamily { return $this->family; }
    public function setFamily(?ServiceFamily $family): static { $this->family = $family; return $this; }
    public function getPosition(): int { return $this->position; }
    public function setPosition(int $position): static { $this->position = $position; return $this; }
    public function isBookable(): bool { return $this->bookable; }
    public function setBookable(bool $bookable): static { $this->bookable = $bookable; return $this; }
    public function getDurationMinutes(): ?int { return $this->durationMinutes; }
    public function setDurationMinutes(?int $minutes): static { $this->durationMinutes = $minutes; return $this; }

    public function getTitre(): ?string
    {
        return $this->titre;
    }

    public function setTitre(string $titre): static
    {
        $this->titre = $titre;

        return $this;
    }

    public function getLogo(): ?string
    {
        return $this->logo;
    }

    public function setLogo(?string $logo): static
    {
        $this->logo = $logo;

        return $this;
    }

    public function getTitreCommentaire(): ?string
    {
        return $this->titreCommentaire;
    }

    public function setTitreCommentaire(?string $titreCommentaire): static
    {
        $this->titreCommentaire = $titreCommentaire;

        return $this;
    }

    public function getDescriptions(): ?string
    {
        return $this->descriptions;
    }

    public function setDescriptions(?string $descriptions): static
    {
        $this->descriptions = $descriptions;

        return $this;
    }

    public function getPhotoService(): ?string
    {
        return $this->photoService;
    }

    public function setPhotoService(?string $photoService): static
    {
        $this->photoService = $photoService;

        return $this;
    }

    /**
     * @return Collection<int, ServiceOfferTranslation>
     */
    public function getTranslations(): Collection
    {
        return $this->translations;
    }

    public function addTranslation(object $translation): void
    {
        if (!$this->translations->contains($translation)) {
            $this->translations->add($translation);
            $translation->setServiceOffer($this);
        }
    }

    public function removeTranslation(ServiceOfferTranslation $translation): static
    {
        if ($this->translations->removeElement($translation)) {
            // set the owning side to null (unless already changed)
            if ($translation->getServiceOffer() === $this) {
                $translation->setServiceOffer(null);
            }
        }

        return $this;
    }


    public function getTranslation(string $locale): ?ServiceOfferTranslation
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
        return ['titre', 'titreCommentaire', 'descriptions'];
    }

    public function getTranslationEntityClass(): string
    {
        return ServiceOfferTranslation::class;
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

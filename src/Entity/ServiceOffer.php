<?php

namespace App\Entity;

use App\Repository\ServiceOfferRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\DBAL\Types\Types;

#[ORM\Entity(repositoryClass: ServiceOfferRepository::class)]
class ServiceOffer
{
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

    public function addTranslation(ServiceOfferTranslation $translation): static
    {
        if (!$this->translations->contains($translation)) {
            $this->translations->add($translation);
            $translation->setServiceOffer($this);
        }

        return $this;
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
}

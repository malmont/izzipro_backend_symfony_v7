<?php

namespace App\Entity;

use App\Repository\VideoRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: VideoRepository::class)]
class Video
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $titre = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $imageDeFond = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $lienVideo = null;

    /**
     * @var Collection<int, VideoTranslation>
     */
    #[ORM\OneToMany(
    mappedBy: 'video', 
    targetEntity: VideoTranslation::class, 
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

    public function getImageDeFond(): ?string
    {
        return $this->imageDeFond;
    }

    public function setImageDeFond(?string $imageDeFond): static
    {
        $this->imageDeFond = $imageDeFond;

        return $this;
    }

    public function getLienVideo(): ?string
    {
        return $this->lienVideo;
    }

    public function setLienVideo(?string $lienVideo): static
    {
        $this->lienVideo = $lienVideo;

        return $this;
    }

    /**
     * @return Collection<int, VideoTranslation>
     */
    public function getTranslations(): Collection
    {
        return $this->translations;
    }

    public function addTranslation(VideoTranslation $translation): static
    {
        if (!$this->translations->contains($translation)) {
            $this->translations->add($translation);
            $translation->setVideo($this);
        }

        return $this;
    }

    public function removeTranslation(VideoTranslation $translation): static
    {
        if ($this->translations->removeElement($translation)) {
            // set the owning side to null (unless already changed)
            if ($translation->getVideo() === $this) {
                $translation->setVideo(null);
            }
        }

        return $this;
    }

    public function getTranslation(string $locale): ?VideoTranslation
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

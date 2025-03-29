<?php

namespace App\Entity;

use App\Repository\CollectionPictureRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CollectionPictureRepository::class)]
class CollectionPicture
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $imageUrl = null;

    /**
     * @var Collection<int, Collections>
     */
    #[ORM\OneToMany(mappedBy: 'photoCollections', targetEntity: Collections::class)]
    private Collection $collections;

    /**
     * @var Collection<int, Commande>
     */
    #[ORM\OneToMany(mappedBy: 'commandepictures', targetEntity: Commande::class)]
    private Collection $commandes;

    public function __construct()
    {
        $this->collections = new ArrayCollection();
        $this->commandes = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getImageUrl(): ?string
    {
        return $this->imageUrl;
    }

    public function setImageUrl(string $imageUrl): static
    {
        $this->imageUrl = $imageUrl;

        return $this;
    }

    /**
     * @return Collection<int, Collections>
     */
    public function getCollections(): Collection
    {
        return $this->collections;
    }

    public function addCollection(Collections $collection): static
    {
        if (!$this->collections->contains($collection)) {
            $this->collections->add($collection);
            $collection->setPhotoCollections($this);
        }

        return $this;
    }

    public function removeCollection(Collections $collection): static
    {
        if ($this->collections->removeElement($collection)) {
            // set the owning side to null (unless already changed)
            if ($collection->getPhotoCollections() === $this) {
                $collection->setPhotoCollections(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Commande>
     */
    public function getCommandes(): Collection
    {
        return $this->commandes;
    }

    public function addCommande(Commande $commande): static
    {
        if (!$this->commandes->contains($commande)) {
            $this->commandes->add($commande);
            $commande->setCommandepictures($this);
        }

        return $this;
    }

    public function removeCommande(Commande $commande): static
    {
        if ($this->commandes->removeElement($commande)) {
            // set the owning side to null (unless already changed)
            if ($commande->getCommandepictures() === $this) {
                $commande->setCommandepictures(null);
            }
        }

        return $this;
    }

    public function __toString(): string
    {
        return $this->imageUrl ?? 'Image sans titre';
    }
}

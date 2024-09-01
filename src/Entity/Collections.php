<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use App\Repository\CollectionsRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CollectionsRepository::class)]
// #[ApiResource]
class Collections
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private ?float $budgetCollection = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $startDateCollection = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $endDateCollection = null;

    #[ORM\Column]
    private ?bool $del = null;

    #[ORM\Column(length: 255)]
    private ?string $nomCollection = null;

    #[ORM\Column(length: 255)]
    private ?string $photoCollection = null;

    #[ORM\ManyToOne(inversedBy: 'collections')]
    private ?User $userCollections = null;

    /**
     * @var Collection<int, Commande>
     */
    #[ORM\OneToMany(mappedBy: 'collections', targetEntity: Commande::class)]
    private Collection $commandes;

    /**
     * @var Collection<int, NoteDeFrais>
     */
    #[ORM\OneToMany(mappedBy: 'Collection', targetEntity: NoteDeFrais::class)]
    private Collection $noteDeFrais;

    public function __construct()
    {
        $this->commandes = new ArrayCollection();
        $this->noteDeFrais = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBudgetCollection(): ?float
    {
        return $this->budgetCollection;
    }

    public function setBudgetCollection(float $budgetCollection): static
    {
        $this->budgetCollection = $budgetCollection;

        return $this;
    }

    public function getStartDateCollection(): ?\DateTimeInterface
    {
        return $this->startDateCollection;
    }

    public function setStartDateCollection(\DateTimeInterface $startDateCollection): static
    {
        $this->startDateCollection = $startDateCollection;

        return $this;
    }

    public function getEndDateCollection(): ?\DateTimeInterface
    {
        return $this->endDateCollection;
    }

    public function setEndDateCollection(\DateTimeInterface $endDateCollection): static
    {
        $this->endDateCollection = $endDateCollection;

        return $this;
    }

    public function isDel(): ?bool
    {
        return $this->del;
    }

    public function setDel(bool $del): static
    {
        $this->del = $del;

        return $this;
    }

    public function getNomCollection(): ?string
    {
        return $this->nomCollection;
    }

    public function setNomCollection(string $nomCollection): static
    {
        $this->nomCollection = $nomCollection;

        return $this;
    }

    public function getPhotoCollection(): ?string
    {
        return $this->photoCollection;
    }

    public function setPhotoCollection(string $photoCollection): static
    {
        $this->photoCollection = $photoCollection;

        return $this;
    }

    public function getUserCollections(): ?User
    {
        return $this->userCollections;
    }

    public function setUserCollections(?User $userCollections): static
    {
        $this->userCollections = $userCollections;

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
            $commande->setCollections($this);
        }

        return $this;
    }

    public function removeCommande(Commande $commande): static
    {
        if ($this->commandes->removeElement($commande)) {
            // set the owning side to null (unless already changed)
            if ($commande->getCollections() === $this) {
                $commande->setCollections(null);
            }
        }

        return $this;
    }
    public function __toString(): string
    {
        return $this->nomCollection;
    }

    /**
     * @return Collection<int, NoteDeFrais>
     */
    public function getNoteDeFrais(): Collection
    {
        return $this->noteDeFrais;
    }

    public function addNoteDeFrai(NoteDeFrais $noteDeFrai): static
    {
        if (!$this->noteDeFrais->contains($noteDeFrai)) {
            $this->noteDeFrais->add($noteDeFrai);
            $noteDeFrai->setCollection($this);
        }

        return $this;
    }

    public function removeNoteDeFrai(NoteDeFrais $noteDeFrai): static
    {
        if ($this->noteDeFrais->removeElement($noteDeFrai)) {
            // set the owning side to null (unless already changed)
            if ($noteDeFrai->getCollection() === $this) {
                $noteDeFrai->setCollection(null);
            }
        }

        return $this;
    }
}

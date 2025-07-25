<?php

namespace App\Entity;

use App\Repository\MarqueRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MarqueRepository::class)]
class Marque
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $titre = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $logosMarques = null;

    /**
     * @var Collection<int, CategorieMarque>
     */
    #[ORM\ManyToMany(targetEntity: CategorieMarque::class, inversedBy: 'marques')]
    private Collection $categories;

    public function __construct()
    {
        $this->categories = new ArrayCollection();
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

    public function getLogosMarques(): ?string
    {
        return $this->logosMarques;
    }

    public function setLogosMarques(?string $logosMarques): static
    {
        $this->logosMarques = $logosMarques;

        return $this;
    }

    /**
     * @return Collection<int, CategorieMarque>
     */
    public function getCategories(): Collection
    {
        return $this->categories;
    }

    public function addCategory(CategorieMarque $category): static
    {
        if (!$this->categories->contains($category)) {
            $this->categories->add($category);
        }

        return $this;
    }

    public function removeCategory(CategorieMarque $category): static
    {
        $this->categories->removeElement($category);

        return $this;
    }
}

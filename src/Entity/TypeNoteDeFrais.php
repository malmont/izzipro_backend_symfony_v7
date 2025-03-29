<?php

namespace App\Entity;

use App\Repository\TypeNoteDeFraisRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TypeNoteDeFraisRepository::class)]
class TypeNoteDeFrais
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $image = null;

    /**
     * @var Collection<int, NoteDeFrais>
     */
    #[ORM\OneToMany(mappedBy: 'typeNoteDeFrais', targetEntity: NoteDeFrais::class)]
    private Collection $noteDeFrais;

    public function __construct()
    {
        $this->noteDeFrais = new ArrayCollection();
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
            $noteDeFrai->setTypeNoteDeFrais($this);
        }

        return $this;
    }

    public function removeNoteDeFrai(NoteDeFrais $noteDeFrai): static
    {
        if ($this->noteDeFrais->removeElement($noteDeFrai)) {
            // set the owning side to null (unless already changed)
            if ($noteDeFrai->getTypeNoteDeFrais() === $this) {
                $noteDeFrai->setTypeNoteDeFrais(null);
            }
        }

        return $this;
    }
    public function __toString(): string
    {
        return $this->name;
    }
}

<?php

namespace App\Entity;

use App\Repository\TransporteurRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TransporteurRepository::class)]
class Transporteur
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $logo = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $contact = null;

    /**
     * @var Collection<int, FraisDePort>
     */
    #[ORM\OneToMany(mappedBy: 'transporteur', targetEntity: FraisDePort::class)]
    private Collection $fraisDePorts;

    public function __construct()
    {
        $this->fraisDePorts = new ArrayCollection();
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

    public function getLogo(): ?string
    {
        return $this->logo;
    }

    public function setLogo(?string $logo): static
    {
        $this->logo = $logo;

        return $this;
    }

    public function getContact(): ?string
    {
        return $this->contact;
    }

    public function setContact(?string $contact): static
    {
        $this->contact = $contact;

        return $this;
    }

    /**
     * @return Collection<int, FraisDePort>
     */
    public function getFraisDePorts(): Collection
    {
        return $this->fraisDePorts;
    }

    public function addFraisDePort(FraisDePort $fraisDePort): static
    {
        if (!$this->fraisDePorts->contains($fraisDePort)) {
            $this->fraisDePorts->add($fraisDePort);
            $fraisDePort->setTransporteur($this);
        }

        return $this;
    }

    public function removeFraisDePort(FraisDePort $fraisDePort): static
    {
        if ($this->fraisDePorts->removeElement($fraisDePort)) {
            // set the owning side to null (unless already changed)
            if ($fraisDePort->getTransporteur() === $this) {
                $fraisDePort->setTransporteur(null);
            }
        }

        return $this;
    }

    public function __toString(): string
    {
        return $this->name; // Retourne le nom du transporteur comme représentation de l'objet
    }
}

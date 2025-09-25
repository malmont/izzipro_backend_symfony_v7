<?php

namespace App\Entity;

use App\Repository\EmbedRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EmbedRepository::class)]
class Embed
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $titre = null;

    #[ORM\Column(length: 255)]
    private ?string $embedUrl = null;

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

    public function getEmbedUrl(): ?string
    {
        return $this->embedUrl;
    }

    public function setEmbedUrl(string $embedUrl): static
    {
        $this->embedUrl = $embedUrl;

        return $this;
    }
}

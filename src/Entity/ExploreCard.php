<?php

namespace App\Entity;

use App\Repository\ExploreCardRepository;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\DBAL\Types\Types;

#[ORM\Entity(repositoryClass: ExploreCardRepository::class)]
class ExploreCard
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private ?bool $isDifferent = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $standardTitle = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $differentTitle = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $link = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $imagePath = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $videoPath = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIsDifferent(): ?bool
    {
        return $this->isDifferent;
    }

    public function setIsDifferent(bool $isDifferent): static
    {
        $this->isDifferent = $isDifferent;

        return $this;
    }

    public function getStandardTitle(): ?string
    {
        return $this->standardTitle;
    }

    public function setStandardTitle(?string $standardTitle): static
    {
        $this->standardTitle = $standardTitle;

        return $this;
    }

    public function getDifferentTitle(): ?string
    {
        return $this->differentTitle;
    }

    public function setDifferentTitle(?string $differentTitle): static
    {
        $this->differentTitle = $differentTitle;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getLink(): ?string
    {
        return $this->link;
    }

    public function setLink(?string $link): static
    {
        $this->link = $link;

        return $this;
    }

    public function getImagePath(): ?string
    {
        return $this->imagePath;
    }

    public function setImagePath(?string $imagePath): static
    {
        $this->imagePath = $imagePath;

        return $this;
    }

    public function getVideoPath(): ?string
    {
        return $this->videoPath;
    }

    public function setVideoPath(?string $videoPath): static
    {
        $this->videoPath = $videoPath;

        return $this;
    }
}

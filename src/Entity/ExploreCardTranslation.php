<?php

namespace App\Entity;

use App\Repository\ExploreCardTranslationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ExploreCardTranslationRepository::class)]
class ExploreCardTranslation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 10)]
    private ?string $language = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $standardTitle = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $differentTitle = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\ManyToOne(inversedBy: 'translations')]
    private ?ExploreCard $exploreCard = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLanguage(): ?string
    {
        return $this->language;
    }

    public function setLanguage(string $language): static
    {
        $this->language = $language;

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

    public function getExploreCard(): ?ExploreCard
    {
        return $this->exploreCard;
    }

    public function setExploreCard(?ExploreCard $exploreCard): static
    {
        $this->exploreCard = $exploreCard;

        return $this;
    }
}

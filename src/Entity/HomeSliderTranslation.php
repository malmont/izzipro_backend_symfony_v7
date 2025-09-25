<?php

namespace App\Entity;

use App\Repository\HomeSliderTranslationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: HomeSliderTranslationRepository::class)]
class HomeSliderTranslation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 10)]
    private ?string $language = null;

    #[ORM\Column(length: 255)]
    private ?string $title = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $buttonMessage = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $buttonUrl = null;

    #[ORM\ManyToOne(inversedBy: 'translations')]
    private ?HomeSlider $homeSlider = null;

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

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

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

    public function getButtonMessage(): ?string
    {
        return $this->buttonMessage;
    }

    public function setButtonMessage(?string $buttonMessage): static
    {
        $this->buttonMessage = $buttonMessage;

        return $this;
    }

    public function getButtonUrl(): ?string
    {
        return $this->buttonUrl;
    }

    public function setButtonUrl(?string $buttonUrl): static
    {
        $this->buttonUrl = $buttonUrl;

        return $this;
    }

    public function getHomeSlider(): ?HomeSlider
    {
        return $this->homeSlider;
    }

    public function setHomeSlider(?HomeSlider $homeSlider): static
    {
        $this->homeSlider = $homeSlider;

        return $this;
    }
}

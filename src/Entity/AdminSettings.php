<?php

namespace App\Entity;

use App\Repository\AdminSettingsRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AdminSettingsRepository::class)]
class AdminSettings
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $navbarComponent = null;

    #[ORM\Column(length: 255)]
    private ?string $styleChoice = null;

    #[ORM\Column(length: 255)]
    private ?string $themeChoice = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNavbarComponent(): ?string
    {
        return $this->navbarComponent;
    }

    public function setNavbarComponent(string $navbarComponent): static
    {
        $this->navbarComponent = $navbarComponent;

        return $this;
    }

    public function getStyleChoice(): ?string
    {
        return $this->styleChoice;
    }

    public function setStyleChoice(string $styleChoice): static
    {
        $this->styleChoice = $styleChoice;

        return $this;
    }

    public function getThemeChoice(): ?string
    {
        return $this->themeChoice;
    }

    public function setThemeChoice(string $themeChoice): static
    {
        $this->themeChoice = $themeChoice;

        return $this;
    }
}

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

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $section1Component = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $typeComponentSection1 = null;

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

    public function getSection1Component(): ?string
    {
        return $this->section1Component;
    }

    public function setSection1Component(?string $section1Component): static
    {
        $this->section1Component = $section1Component;

        return $this;
    }

    public function getTypeComponentSection1(): ?string
    {
        return $this->typeComponentSection1;
    }

    public function setTypeComponentSection1(?string $typeComponentSection1): static
    {
        $this->typeComponentSection1 = $typeComponentSection1;

        return $this;
    }
}

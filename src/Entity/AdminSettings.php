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

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $selectTypeProductFetch = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $section2Component = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $typeComponentSection2 = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $section3Component = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $typeComponentSection3 = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $section4Component = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $typeComponentSection4 = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $selectTypeProductFetchSection2 = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $selectTypeProductFetchSection3 = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $selectTypeProductFetchSection4 = null;

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

    public function getSelectTypeProductFetch(): ?string
    {
        return $this->selectTypeProductFetch;
    }

    public function setSelectTypeProductFetch(?string $selectTypeProductFetch): static
    {
        $this->selectTypeProductFetch = $selectTypeProductFetch;

        return $this;
    }

    public function getSection2Component(): ?string
    {
        return $this->section2Component;
    }

    public function setSection2Component(?string $section2Component): static
    {
        $this->section2Component = $section2Component;

        return $this;
    }

    public function getTypeComponentSection2(): ?string
    {
        return $this->typeComponentSection2;
    }

    public function setTypeComponentSection2(?string $typeComponentSection2): static
    {
        $this->typeComponentSection2 = $typeComponentSection2;

        return $this;
    }

    public function getSection3Component(): ?string
    {
        return $this->section3Component;
    }

    public function setSection3Component(?string $section3Component): static
    {
        $this->section3Component = $section3Component;

        return $this;
    }

    public function getTypeComponentSection3(): ?string
    {
        return $this->typeComponentSection3;
    }

    public function setTypeComponentSection3(?string $typeComponentSection3): static
    {
        $this->typeComponentSection3 = $typeComponentSection3;

        return $this;
    }

    public function getSection4Component(): ?string
    {
        return $this->section4Component;
    }

    public function setSection4Component(?string $section4Component): static
    {
        $this->section4Component = $section4Component;

        return $this;
    }

    public function getTypeComponentSection4(): ?string
    {
        return $this->typeComponentSection4;
    }

    public function setTypeComponentSection4(?string $typeComponentSection4): static
    {
        $this->typeComponentSection4 = $typeComponentSection4;

        return $this;
    }

    public function getSelectTypeProductFetchSection2(): ?string
    {
        return $this->selectTypeProductFetchSection2;
    }

    public function setSelectTypeProductFetchSection2(?string $selectTypeProductFetchSection2): static
    {
        $this->selectTypeProductFetchSection2 = $selectTypeProductFetchSection2;

        return $this;
    }

    public function getSelectTypeProductFetchSection3(): ?string
    {
        return $this->selectTypeProductFetchSection3;
    }

    public function setSelectTypeProductFetchSection3(?string $selectTypeProductFetchSection3): static
    {
        $this->selectTypeProductFetchSection3 = $selectTypeProductFetchSection3;

        return $this;
    }

    public function getSelectTypeProductFetchSection4(): ?string
    {
        return $this->selectTypeProductFetchSection4;
    }

    public function setSelectTypeProductFetchSection4(?string $selectTypeProductFetchSection4): static
    {
        $this->selectTypeProductFetchSection4 = $selectTypeProductFetchSection4;

        return $this;
    }
}

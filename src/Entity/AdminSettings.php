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

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $section5Component = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $typeComponentSection5 = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $section6Component = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $typeComponentSection6 = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $section7Component = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $typeComponentSection7 = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $selectTypeProductFetchSection5 = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $selectTypeProductFetchSection6 = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $selectTypeProductFetchSection7 = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $typeCategoryCard = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $detailsProductCardComponent = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $cartItemCardComponent = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $totalCardComponent = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $checkoutCardComponent = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $accountDashboardComponent = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $orderListCardComponent = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $adressListCardComponent = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $carrierListCardComponent = null;

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

    public function getSection5Component(): ?string
    {
        return $this->section5Component;
    }

    public function setSection5Component(?string $section5Component): static
    {
        $this->section5Component = $section5Component;

        return $this;
    }

    public function getTypeComponentSection5(): ?string
    {
        return $this->typeComponentSection5;
    }

    public function setTypeComponentSection5(?string $typeComponentSection5): static
    {
        $this->typeComponentSection5 = $typeComponentSection5;

        return $this;
    }

    public function getSection6Component(): ?string
    {
        return $this->section6Component;
    }

    public function setSection6Component(?string $section6Component): static
    {
        $this->section6Component = $section6Component;

        return $this;
    }

    public function getTypeComponentSection6(): ?string
    {
        return $this->typeComponentSection6;
    }

    public function setTypeComponentSection6(?string $typeComponentSection6): static
    {
        $this->typeComponentSection6 = $typeComponentSection6;

        return $this;
    }

    public function getSection7Component(): ?string
    {
        return $this->section7Component;
    }

    public function setSection7Component(?string $section7Component): static
    {
        $this->section7Component = $section7Component;

        return $this;
    }

    public function getTypeComponentSection7(): ?string
    {
        return $this->typeComponentSection7;
    }

    public function setTypeComponentSection7(?string $typeComponentSection7): static
    {
        $this->typeComponentSection7 = $typeComponentSection7;

        return $this;
    }

    public function getSelectTypeProductFetchSection5(): ?string
    {
        return $this->selectTypeProductFetchSection5;
    }

    public function setSelectTypeProductFetchSection5(?string $selectTypeProductFetchSection5): static
    {
        $this->selectTypeProductFetchSection5 = $selectTypeProductFetchSection5;

        return $this;
    }

    public function getSelectTypeProductFetchSection6(): ?string
    {
        return $this->selectTypeProductFetchSection6;
    }

    public function setSelectTypeProductFetchSection6(?string $selectTypeProductFetchSection6): static
    {
        $this->selectTypeProductFetchSection6 = $selectTypeProductFetchSection6;

        return $this;
    }

    public function getSelectTypeProductFetchSection7(): ?string
    {
        return $this->selectTypeProductFetchSection7;
    }

    public function setSelectTypeProductFetchSection7(?string $selectTypeProductFetchSection7): static
    {
        $this->selectTypeProductFetchSection7 = $selectTypeProductFetchSection7;

        return $this;
    }

    public function getTypeCategoryCard(): ?string
    {
        return $this->typeCategoryCard;
    }

    public function setTypeCategoryCard(?string $typeCategoryCard): static
    {
        $this->typeCategoryCard = $typeCategoryCard;

        return $this;
    }

    public function getDetailsProductCardComponent(): ?string
    {
        return $this->detailsProductCardComponent;
    }

    public function setDetailsProductCardComponent(?string $detailsProductCardComponent): static
    {
        $this->detailsProductCardComponent = $detailsProductCardComponent;

        return $this;
    }

    public function getCartItemCardComponent(): ?string
    {
        return $this->cartItemCardComponent;
    }

    public function setCartItemCardComponent(?string $cartItemCardComponent): static
    {
        $this->cartItemCardComponent = $cartItemCardComponent;

        return $this;
    }

    public function getTotalCardComponent(): ?string
    {
        return $this->totalCardComponent;
    }

    public function setTotalCardComponent(?string $totalCardComponent): static
    {
        $this->totalCardComponent = $totalCardComponent;

        return $this;
    }

    public function getCheckoutCardComponent(): ?string
    {
        return $this->checkoutCardComponent;
    }

    public function setCheckoutCardComponent(?string $checkoutCardComponent): static
    {
        $this->checkoutCardComponent = $checkoutCardComponent;

        return $this;
    }

    public function getAccountDashboardComponent(): ?string
    {
        return $this->accountDashboardComponent;
    }

    public function setAccountDashboardComponent(?string $accountDashboardComponent): static
    {
        $this->accountDashboardComponent = $accountDashboardComponent;

        return $this;
    }

    public function getOrderListCardComponent(): ?string
    {
        return $this->orderListCardComponent;
    }

    public function setOrderListCardComponent(?string $orderListCardComponent): static
    {
        $this->orderListCardComponent = $orderListCardComponent;

        return $this;
    }

    public function getAdressListCardComponent(): ?string
    {
        return $this->adressListCardComponent;
    }

    public function setAdressListCardComponent(?string $adressListCardComponent): static
    {
        $this->adressListCardComponent = $adressListCardComponent;

        return $this;
    }

    public function getCarrierListCardComponent(): ?string
    {
        return $this->carrierListCardComponent;
    }

    public function setCarrierListCardComponent(?string $carrierListCardComponent): static
    {
        $this->carrierListCardComponent = $carrierListCardComponent;

        return $this;
    }
}

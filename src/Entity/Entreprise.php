<?php

namespace App\Entity;

use App\Repository\EntrepriseRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;


#[ORM\Entity(repositoryClass: EntrepriseRepository::class)]
class Entreprise implements TranslatableInterface
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
    private ?string $email = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $tel = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $website = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $ein = null;

    #[ORM\Column(length: 255, nullable: true)] 
    private ?string $tvaIntracommunautaire = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $conditionOfUse = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $LegalNotice = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $privacyPolicy = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $adress = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $Apropos = null;

    #[ORM\OneToOne(mappedBy: 'entreprise', targetEntity: AddressEntreprise::class, cascade: ['persist','remove'])]
    private ?AddressEntreprise $addressEntreprise = null;

    /**
     * @var Collection<int, EntrepriseTranslation>
     */
    #[ORM\OneToMany(
    mappedBy: 'entreprise', 
    targetEntity: EntrepriseTranslation::class, 
    cascade: ['persist', 'remove'], 
    orphanRemoval: true,
    fetch: 'EXTRA_LAZY'
    )]
    private Collection $translations;

    /**
     * @var Collection<int, SocialNetwork>
     */
    #[ORM\OneToMany(
        mappedBy: 'entreprise',
        targetEntity: SocialNetwork::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
        fetch: 'EXTRA_LAZY'
    )]
    private Collection $socialNetworks;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $faviconFilename = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $facebookPixelId = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private ?bool $isBoutiqueActive = true;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private ?bool $isBoussoleEsgActive = false;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private ?bool $isMemoireVivanteActive = false;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private ?bool $isLandingPageActive = true;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $metaTitle = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $metaDescription = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $seoKeywords = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $ogImage = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $googleSiteVerification = null;

    public function __construct()
    {
        $this->translations = new ArrayCollection();
        $this->socialNetworks = new ArrayCollection();
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

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getTel(): ?string
    {
        return $this->tel;
    }

    public function setTel(?string $tel): static
    {
        $this->tel = $tel;

        return $this;
    }

    public function getWebsite(): ?string
    {
        return $this->website;
    }

    public function setWebsite(?string $website): static
    {
        $this->website = $website;

        return $this;
    }

    public function getEin(): ?string
    {
        return $this->ein;
    }

    public function setEin(?string $ein): static
    {
        $this->ein = $ein;

        return $this;
    }

    public function getTvaIntracommunautaire(): ?string
    {
        return $this->tvaIntracommunautaire;
    }

    public function setTvaIntracommunautaire(?string $tvaIntracommunautaire): static
    {
        $this->tvaIntracommunautaire = $tvaIntracommunautaire;

        return $this;
    }

    public function getConditionOfUse(): ?string
    {
        return $this->conditionOfUse;
    }

    public function setConditionOfUse(?string $conditionOfUse): static
    {
        $this->conditionOfUse = $conditionOfUse;

        return $this;
    }

    public function getLegalNotice(): ?string
    {
        return $this->LegalNotice;
    }

    public function setLegalNotice(?string $LegalNotice): static
    {
        $this->LegalNotice = $LegalNotice;

        return $this;
    }

    public function getPrivacyPolicy(): ?string
    {
        return $this->privacyPolicy;
    }

    public function setPrivacyPolicy(?string $privacyPolicy): static
    {
        $this->privacyPolicy = $privacyPolicy;

        return $this;
    }

    public function getAdress(): ?string
    {
        return $this->adress;
    }

    public function setAdress(?string $adress): static
    {
        $this->adress = $adress;

        return $this;
    }

    public function getApropos(): ?string
    {
        return $this->Apropos;
    }

    public function setApropos(?string $Apropos): static
    {
        $this->Apropos = $Apropos;

        return $this;
    }

    public function getAddressEntreprise(): ?AddressEntreprise
    {
        return $this->addressEntreprise;
    }

    public function setAddressEntreprise(?AddressEntreprise $addressEntreprise): static
    {
        // unset the owning side of the relation if necessary
        if ($addressEntreprise === null && $this->addressEntreprise !== null) {
            $this->addressEntreprise->setEntreprise(null);
        }

        // set the owning side of the relation if necessary
        if ($addressEntreprise !== null && $addressEntreprise->getEntreprise() !== $this) {
            $addressEntreprise->setEntreprise($this);
        }

        $this->addressEntreprise = $addressEntreprise;

        return $this;
    }

    public function __toString(): string
    {
        return $this->name;
    }



    /**
     * @return Collection<int, EntrepriseTranslation>
     */
    public function getTranslations(): Collection
    {
        return $this->translations;
    }

    public function addTranslation(object $translation): void
    {
        if (!$this->translations->contains($translation)) {
            $this->translations->add($translation);
            $translation->setEntreprise($this);
        }
    }

    public function removeTranslation(EntrepriseTranslation $translation): static
    {
        if ($this->translations->removeElement($translation)) {
            // set the owning side to null (unless already changed)
            if ($translation->getEntreprise() === $this) {
                $translation->setEntreprise(null);
            }
        }

        return $this;
    }

    public function getTranslation(string $locale): ?EntrepriseTranslation
    {
        foreach ($this->translations as $translation) {
            if ($translation->getLanguage() === $locale) {
                return $translation;
            }
        }

        foreach ($this->translations as $translation) {
            if ($translation->getLanguage() === 'fr') {
                return $translation;
            }
        }
        
        return $this->translations->first() ?: null;
    }
    public function getTranslatableFields(): array
    {
        return ['conditionOfUse', 'LegalNotice', 'privacyPolicy', 'Apropos'];
    }

    public function getTranslationEntityClass(): string
    {
        return EntrepriseTranslation::class;
    }

    public function findTranslationByLocale(string $locale): ?object
    {
        foreach ($this->translations as $translation) {
            if ($translation->getLanguage() === $locale) {
                return $translation;
            }
        }
        return null;
    }

    public function getFaviconFilename(): ?string
    {
        return $this->faviconFilename;
    }

    public function setFaviconFilename(?string $faviconFilename): static
    {
        $this->faviconFilename = $faviconFilename;

        return $this;
    }



    public function getFacebookPixelId(): ?string
    {
        return $this->facebookPixelId;
    }

    public function setFacebookPixelId(?string $facebookPixelId): static
    {
        $this->facebookPixelId = $facebookPixelId;

        return $this;
    }

    public function isBoutiqueActive(): ?bool
    {
        return $this->isBoutiqueActive;
    }

    public function setIsBoutiqueActive(bool $isBoutiqueActive): static
    {
        $this->isBoutiqueActive = $isBoutiqueActive;

        return $this;
    }

    public function isBoussoleEsgActive(): ?bool
    {
        return $this->isBoussoleEsgActive;
    }

    public function setIsBoussoleEsgActive(bool $isBoussoleEsgActive): static
    {
        $this->isBoussoleEsgActive = $isBoussoleEsgActive;

        return $this;
    }

    public function isMemoireVivanteActive(): ?bool
    {
        return $this->isMemoireVivanteActive;
    }

    public function setIsMemoireVivanteActive(bool $isMemoireVivanteActive): static
    {
        $this->isMemoireVivanteActive = $isMemoireVivanteActive;

        return $this;
    }

    public function isLandingPageActive(): ?bool
    {
        return $this->isLandingPageActive;
    }

    public function setIsLandingPageActive(bool $isLandingPageActive): static
    {
        $this->isLandingPageActive = $isLandingPageActive;

        return $this;
    }


    /**
     * @return Collection<int, SocialNetwork>
     */
    public function getSocialNetworks(): Collection
    {
        return $this->socialNetworks;
    }

    public function addSocialNetwork(SocialNetwork $socialNetwork): static
    {
        if (!$this->socialNetworks->contains($socialNetwork)) {
            $this->socialNetworks->add($socialNetwork);
            $socialNetwork->setEntreprise($this);
        }

        return $this;
    }

    public function removeSocialNetwork(SocialNetwork $socialNetwork): static
    {
        if ($this->socialNetworks->removeElement($socialNetwork)) {
            if ($socialNetwork->getEntreprise() === $this) {
                $socialNetwork->setEntreprise(null);
            }
        }

        return $this;
    }

    public function getMetaTitle(): ?string
    {
        return $this->metaTitle;
    }

    public function setMetaTitle(?string $metaTitle): static
    {
        $this->metaTitle = $metaTitle;

        return $this;
    }

    public function getMetaDescription(): ?string
    {
        return $this->metaDescription;
    }

    public function setMetaDescription(?string $metaDescription): static
    {
        $this->metaDescription = $metaDescription;

        return $this;
    }

    public function getSeoKeywords(): ?string
    {
        return $this->seoKeywords;
    }

    public function setSeoKeywords(?string $seoKeywords): static
    {
        $this->seoKeywords = $seoKeywords;

        return $this;
    }

    public function getOgImage(): ?string
    {
        return $this->ogImage;
    }

    public function setOgImage(?string $ogImage): static
    {
        $this->ogImage = $ogImage;

        return $this;
    }

    public function getGoogleSiteVerification(): ?string
    {
        return $this->googleSiteVerification;
    }

    public function setGoogleSiteVerification(?string $googleSiteVerification): static
    {
        $this->googleSiteVerification = $googleSiteVerification;

        return $this;
    }
}

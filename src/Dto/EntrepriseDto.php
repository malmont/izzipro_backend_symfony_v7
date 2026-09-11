<?php
namespace App\Dto;

use App\Entity\Entreprise;

class EntrepriseDto
{
    public ?int $id = null;
    public ?string $name = null;
    public ?string $logo = null;
    public ?string $faviconUrl = null;
    public ?string $email = null;
    public ?string $apropos = null;
    public ?string $tel = null;
    public ?string $website = null;
    public ?string $ein = null;
    public ?string $tvaIntracommunautaire = null;
    public ?string $conditionOfUse = null;
    public ?string $LegalNotice = null;
    public ?string $privacyPolicy = null;
    public ?string $adress = null;
    public ?string $street1 = null;
    public ?string $street2 = null;
    public ?string $city = null;
    public ?string $zip = null;
    public ?string $country = null;
    public ?string $province = null;
    public ?string $facebookPixelId = null;
    public bool $isBoutiqueActive = true;
    public bool $isLandingPageActive = true;
    public bool $isBoussoleEsgActive = false;
    public bool $isMemoireVivanteActive = false;
    public ?string $metaTitle = null;
    public ?string $metaDescription = null;
    public ?string $seoKeywords = null;
    public ?string $ogImage = null;
    public ?string $googleSiteVerification = null;
    /** @var SocialNetworkDto[] */
    public array $socialNetworks = [];


    public static function fromEntity(Entreprise $entreprise, string $host, string $locale): self
    {
        $dto = new self();
        $translation = $entreprise->getTranslation($locale);
        $dto->id = $entreprise->getId();
        $dto->name = $entreprise->getName();
        $dto->email = $entreprise->getEmail();
        $dto->tel = $entreprise->getTel();
        $dto->website = $entreprise->getWebsite();
        $dto->ein = $entreprise->getEin();
        $dto->tvaIntracommunautaire = $entreprise->getTvaIntracommunautaire();
        $dto->facebookPixelId = $entreprise->getFacebookPixelId();
        $dto->isBoutiqueActive = $entreprise->isBoutiqueActive() ?? true;
        $dto->isLandingPageActive = $entreprise->isLandingPageActive() ?? true;
        $dto->isBoussoleEsgActive = $entreprise->isBoussoleEsgActive() ?? false;
        $dto->isMemoireVivanteActive = $entreprise->isMemoireVivanteActive() ?? false;
        
        $addressObj = $entreprise->getAddressEntreprise();

        if ($addressObj) {
            $dto->street1 = $addressObj->getStreet1();
            $dto->street2 = $addressObj->getStreet2();
            $dto->city = $addressObj->getCity();
            $dto->zip = $addressObj->getZip();
            $dto->country = $addressObj->getCountry();
            $dto->province = $addressObj->getState();
            
            // On construit l'adresse complète pour le champ 'adress'
            $parts = array_filter([
                $dto->street1,
                $dto->street2,
                $dto->zip . ' ' . $dto->city,
                $dto->country
            ]);
            $dto->adress = implode(', ', $parts);
        } else {
            $dto->adress = $entreprise->getAdress();
        }
        
        $imagePath = $entreprise->getLogo();
        if (str_starts_with((string)$imagePath, 'http')) {
            $dto->logo = $imagePath;
        } else if ($imagePath) {
            $dto->logo = rtrim($host, '/') . '/assets/uploads/email-logos/' . $imagePath;
        }
        $faviconPath = $entreprise->getFaviconFilename();
        if (str_starts_with((string)$faviconPath, 'http')) {
            $dto->faviconUrl = $faviconPath;
        } else if ($faviconPath) {
            $dto->faviconUrl = rtrim($host, '/') . '/assets/uploads/email-logos/' . $faviconPath;
        }

        $dto->metaTitle = $entreprise->getMetaTitle();
        $dto->metaDescription = $entreprise->getMetaDescription();
        $dto->seoKeywords = $entreprise->getSeoKeywords();
        $dto->googleSiteVerification = $entreprise->getGoogleSiteVerification();

        $ogImagePath = $entreprise->getOgImage();
        if (str_starts_with((string)$ogImagePath, 'http')) {
            $dto->ogImage = $ogImagePath;
        } else if ($ogImagePath) {
            $dto->ogImage = rtrim($host, '/') . '/assets/uploads/email-logos/' . $ogImagePath;
        } else {
            $dto->ogImage = null;
        }

        $dto->conditionOfUse = $translation?->getConditionOfUse() ?? $entreprise->getConditionOfUse();
        $dto->LegalNotice = $translation?->getLegalNotice() ?? $entreprise->getLegalNotice();
        $dto->privacyPolicy = $translation?->getPrivacyPolicy() ?? $entreprise->getPrivacyPolicy();
        $dto->apropos = $translation?->getApropos() ?? $entreprise->getApropos();

        foreach ($entreprise->getSocialNetworks() as $socialNetwork) {
            $dto->socialNetworks[] = SocialNetworkDto::fromEntity($socialNetwork);
        }

        return $dto;
    }
}
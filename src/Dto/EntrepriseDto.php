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
    /** Devise du site (ISO 4217) : tous les montants de la boutique sont dans cette devise (08/10/2026) */
    public string $currency = 'CAD';
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
        $dto->currency = $entreprise->getCurrency();
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
        
        $dto->logo = self::uploadUrl($entreprise->getLogo(), $host);
        $dto->faviconUrl = self::uploadUrl($entreprise->getFaviconFilename(), $host);

        $dto->metaTitle = $entreprise->getMetaTitle();
        $dto->metaDescription = $entreprise->getMetaDescription();
        $dto->seoKeywords = $entreprise->getSeoKeywords();
        $dto->googleSiteVerification = $entreprise->getGoogleSiteVerification();

        $dto->ogImage = self::uploadUrl($entreprise->getOgImage(), $host);

        $dto->conditionOfUse = $translation?->getConditionOfUse() ?? $entreprise->getConditionOfUse();
        $dto->LegalNotice = $translation?->getLegalNotice() ?? $entreprise->getLegalNotice();
        $dto->privacyPolicy = $translation?->getPrivacyPolicy() ?? $entreprise->getPrivacyPolicy();
        $dto->apropos = $translation?->getApropos() ?? $entreprise->getApropos();

        foreach ($entreprise->getSocialNetworks() as $socialNetwork) {
            $dto->socialNetworks[] = SocialNetworkDto::fromEntity($socialNetwork);
        }

        return $dto;
    }

    /**
     * Logo, favicon ou image de partage : URL absolue gardée telle quelle ; fichier du dossier email-logos. Fichier
     * disparu du stockage : null plutôt qu'une adresse en 404 (13/10/2026 : le frontend affiche alors le nom ou les
     * initiales du site).
     */
    private static function uploadUrl(?string $stored, string $host): ?string
    {
        if ($stored === null || $stored === '') {
            return null;
        }
        if (str_starts_with($stored, 'http')) {
            return $stored;
        }
        if (!str_contains($stored, '/') && !is_file(\dirname(__DIR__, 2) . '/var/storage/public_bucket/assets/uploads/email-logos/' . $stored)) {
            return null;
        }

        return rtrim($host, '/') . '/assets/uploads/email-logos/' . $stored;
    }
}

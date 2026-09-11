<?php

namespace App\Services\EntrepriseService;

use App\Entity\Entreprise;
use App\Dto\EntrepriseDto;
use App\Repository\EntrepriseRepository;
use App\Services\TenantEntityManagerProvider;
use App\Entity\EntrepriseTranslation;

class EntrepriseService
{
    private EntrepriseRepository $repository;
    private TenantEntityManagerProvider $emProvider;

    public function __construct(TenantEntityManagerProvider $emProvider)
    {
        $this->emProvider = $emProvider;
        $em = $emProvider->getEntityManager();
        $this->repository = $em->getRepository(Entreprise::class);
    }

    public function getEntrepriseByIdAndLocale(int $id, string $host, string $locale): ?EntrepriseDto
    {
        $entreprise = $this->repository->findByIdAndLocale($id, $locale);

        if (!$entreprise) {
            return null;
        }
        return EntrepriseDto::fromEntity($entreprise, $host, $locale);
    }

    public function createEntreprise(EntrepriseDto $dto): EntrepriseDto
    {
        $em = $this->emProvider->getEntityManager();
        $entreprise = new Entreprise();
        $entreprise->setName($dto->name);
        $entreprise->setLogo($dto->logo);
        $entreprise->setFaviconFilename($dto->faviconUrl);
        $entreprise->setEmail($dto->email);
        $entreprise->setTel($dto->tel);
        $entreprise->setWebsite($dto->website);
        $entreprise->setEin($dto->ein);
        $entreprise->setTvaIntracommunautaire($dto->tvaIntracommunautaire);
        $entreprise->setAdress($dto->adress);
        $entreprise->setFacebookPixelId($dto->facebookPixelId);
        $entreprise->setMetaTitle($dto->metaTitle);
        $entreprise->setMetaDescription($dto->metaDescription);
        $entreprise->setSeoKeywords($dto->seoKeywords);
        $entreprise->setOgImage($dto->ogImage);
        $entreprise->setGoogleSiteVerification($dto->googleSiteVerification);
        $translation = new EntrepriseTranslation();
        $translation->setLanguage('fr');
        $translation->setConditionOfUse($dto->conditionOfUse);
        $translation->setLegalNotice($dto->LegalNotice);
        $translation->setPrivacyPolicy($dto->privacyPolicy);
        $translation->setApropos($dto->apropos);
        $entreprise->addTranslation($translation);

        $em->persist($entreprise);
        $em->flush();

        $dto->id = $entreprise->getId();
        return $dto;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateEntreprise(int $id, array $data, string $host, string $locale): ?EntrepriseDto
    {
        $em = $this->emProvider->getEntityManager();
        $repository = $em->getRepository(Entreprise::class);
        $entreprise = $repository->find($id);

        if (!$entreprise) {
            return null;
        }

        if (array_key_exists('name', $data)) {
            $entreprise->setName($data['name']);
        }
        if (array_key_exists('email', $data)) {
            $entreprise->setEmail($data['email']);
        }
        if (array_key_exists('tel', $data)) {
            $entreprise->setTel($data['tel']);
        }
        if (array_key_exists('website', $data)) {
            $entreprise->setWebsite($data['website']);
        }
        if (array_key_exists('ein', $data)) {
            $entreprise->setEin($data['ein']);
        }
        if (array_key_exists('tvaIntracommunautaire', $data)) {
            $entreprise->setTvaIntracommunautaire($data['tvaIntracommunautaire']);
        }
        if (array_key_exists('adress', $data)) {
            $entreprise->setAdress($data['adress']);
        }
        if (array_key_exists('facebookPixelId', $data)) {
            $entreprise->setFacebookPixelId($data['facebookPixelId']);
        }
        if (array_key_exists('logo', $data)) {
            $entreprise->setLogo($data['logo']);
        }
        if (array_key_exists('faviconUrl', $data)) {
            $entreprise->setFaviconFilename($data['faviconUrl']);
        }
        if (array_key_exists('faviconFilename', $data)) {
            $entreprise->setFaviconFilename($data['faviconFilename']);
        }
        if (array_key_exists('isBoutiqueActive', $data)) {
            $entreprise->setIsBoutiqueActive((bool)$data['isBoutiqueActive']);
        }
        if (array_key_exists('isLandingPageActive', $data)) {
            $entreprise->setIsLandingPageActive((bool)$data['isLandingPageActive']);
        }
        if (array_key_exists('isBoussoleEsgActive', $data)) {
            $entreprise->setIsBoussoleEsgActive((bool)$data['isBoussoleEsgActive']);
        }
        if (array_key_exists('isMemoireVivanteActive', $data)) {
            $entreprise->setIsMemoireVivanteActive((bool)$data['isMemoireVivanteActive']);
        }

        // SEO Fields
        if (array_key_exists('metaTitle', $data)) {
            $entreprise->setMetaTitle($data['metaTitle']);
        }
        if (array_key_exists('metaDescription', $data)) {
            $entreprise->setMetaDescription($data['metaDescription']);
        }
        if (array_key_exists('seoKeywords', $data)) {
            $entreprise->setSeoKeywords($data['seoKeywords']);
        }
        if (array_key_exists('ogImage', $data)) {
            $entreprise->setOgImage($data['ogImage']);
        }
        if (array_key_exists('googleSiteVerification', $data)) {
            $entreprise->setGoogleSiteVerification($data['googleSiteVerification']);
        }

        // Translations if provided
        $translation = $entreprise->getTranslation($locale);
        if (!$translation && (isset($data['conditionOfUse']) || isset($data['LegalNotice']) || isset($data['privacyPolicy']) || isset($data['apropos']))) {
            $translation = new EntrepriseTranslation();
            $translation->setLanguage($locale);
            $entreprise->addTranslation($translation);
        }
        if ($translation) {
            if (array_key_exists('conditionOfUse', $data)) {
                $translation->setConditionOfUse($data['conditionOfUse']);
            }
            if (array_key_exists('LegalNotice', $data)) {
                $translation->setLegalNotice($data['LegalNotice']);
            }
            if (array_key_exists('privacyPolicy', $data)) {
                $translation->setPrivacyPolicy($data['privacyPolicy']);
            }
            if (array_key_exists('apropos', $data)) {
                $translation->setApropos($data['apropos']);
            }
        }

        $em->flush();

        return EntrepriseDto::fromEntity($entreprise, $host, $locale);
    }
}

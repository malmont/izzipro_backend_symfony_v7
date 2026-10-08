<?php

namespace App\Services\EntrepriseService;

use App\Services\LandingPageSettingsService\LegalTextPolicy;
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

    /** Clés du PUT => lecture de leur valeur (fiche ou traduction exacte de la langue) */
    private const SNAPSHOT_GETTERS = [
        'name' => 'getName', 'email' => 'getEmail', 'tel' => 'getTel', 'website' => 'getWebsite', 'ein' => 'getEin',
        'tvaIntracommunautaire' => 'getTvaIntracommunautaire', 'adress' => 'getAdress', 'facebookPixelId' => 'getFacebookPixelId',
        'logo' => 'getLogo', 'faviconUrl' => 'getFaviconFilename', 'faviconFilename' => 'getFaviconFilename',
        'isBoutiqueActive' => 'isBoutiqueActive', 'isLandingPageActive' => 'isLandingPageActive',
        'isBoussoleEsgActive' => 'isBoussoleEsgActive', 'isMemoireVivanteActive' => 'isMemoireVivanteActive',
        'metaTitle' => 'getMetaTitle', 'metaDescription' => 'getMetaDescription', 'seoKeywords' => 'getSeoKeywords',
        'ogImage' => 'getOgImage', 'googleSiteVerification' => 'getGoogleSiteVerification', 'currency' => 'getCurrency',
    ];
    private const TRANSLATION_GETTERS = ['LegalNotice' => 'getLegalNotice', 'conditionOfUse' => 'getConditionOfUse', 'privacyPolicy' => 'getPrivacyPolicy', 'apropos' => 'getApropos'];

    /**
     * Valeurs actuelles des clés reconnues par le PUT (journal des écritures). null si la fiche n'existe pas.
     *
     * @param list<string> $keys
     * @return array<string, mixed>|null
     */
    public function snapshot(int $id, array $keys, string $locale): ?array
    {
        $entreprise = $this->emProvider->getEntityManager()->getRepository(Entreprise::class)->find($id);
        if (!$entreprise instanceof Entreprise) {
            return null;
        }
        $translation = null;
        foreach ($entreprise->getTranslations() as $candidate) {
            if ($candidate->getLanguage() === $locale) {
                $translation = $candidate;
            }
        }
        $values = [];
        foreach ($keys as $key) {
            if (isset(self::SNAPSHOT_GETTERS[$key])) {
                $values[$key] = $entreprise->{self::SNAPSHOT_GETTERS[$key]}();
            } elseif (isset(self::TRANSLATION_GETTERS[$key])) {
                $values[$key] = $translation?->{self::TRANSLATION_GETTERS[$key]}();
            }
        }

        return $values;
    }

    /**
     * Textes longs affichés à tous les visiteurs (mentions légales…) : HTML contrôlé par LegalTextPolicy avant toute
     * écriture. Un texte envoyé tel qu'il est déjà enregistré n'est pas contrôlé, pour qu'un site dont les anciens
     * textes ne passent pas (inventaire : app:entreprise:check-legal-texts) puisse encore enregistrer le reste de sa
     * fiche ; tout texte nouveau ou modifié doit passer.
     *
     * @param array<string, mixed> $data
     * @throws EntrepriseValidationException 422
     */
    private function checkLegalTexts(Entreprise $entreprise, array $data, string $locale): void
    {
        $current = null;
        foreach ($entreprise->getTranslations() as $candidate) {
            if ($candidate->getLanguage() === $locale) {
                $current = $candidate;
            }
        }
        $getters = ['LegalNotice' => 'getLegalNotice', 'conditionOfUse' => 'getConditionOfUse', 'privacyPolicy' => 'getPrivacyPolicy', 'apropos' => 'getApropos'];
        $errors = [];
        foreach (LegalTextPolicy::FIELDS as $field) {
            if (!array_key_exists($field, $data) || $data[$field] === null || $data[$field] === '') {
                continue;
            }
            if (!is_string($data[$field])) {
                $errors[] = ['path' => $field, 'message' => 'texte attendu'];
                continue;
            }
            if ($current !== null && $data[$field] === $current->{$getters[$field]}()) {
                continue;
            }
            foreach (LegalTextPolicy::problems($data[$field]) as $problem) {
                $errors[] = ['path' => $field, 'message' => $problem];
            }
        }
        if ($errors) {
            throw new EntrepriseValidationException($errors);
        }
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
        $this->checkLegalTexts($entreprise, $data, $locale);

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
        if (array_key_exists('currency', $data)) {
            // Devise du site : code ISO 4217 (3 lettres), lu par toute la boutique
            if (!is_string($data['currency']) || !preg_match('/^[A-Za-z]{3}$/', $data['currency'])) {
                throw new EntrepriseValidationException([['path' => 'currency', 'message' => 'code de devise ISO 4217 attendu (3 lettres, ex. CAD)']]);
            }
            $entreprise->setCurrency(strtoupper($data['currency']));
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

        // Traductions : celle de la langue demandée exactement. getTranslation() se replie sur le français : écrire
        // l'anglais d'un site qui n'avait que le français écrasait jusqu'au 06/10/2026 les textes français.
        $translation = null;
        foreach ($entreprise->getTranslations() as $candidate) {
            if ($candidate->getLanguage() === $locale) {
                $translation = $candidate;
            }
        }
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

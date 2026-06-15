<?php
// src/Services/GemsuiteCompanySyncHandler.php

namespace App\Services\GemsuiteImporterService;

use App\Entity\Entreprise;
use App\Entity\HomeSlider;
use App\Entity\AddressEntreprise;
use App\Entity\ExploreCard;
use App\Services\TranslationGeneratorService\TranslationGeneratorService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use App\Services\GemsuiteImporterService\GemsuiteImageUrlBuilder;
use App\Entity\EmailConfiguration;
use App\Services\SocialNetworkService\SocialNetworkService;

class GemsuiteCompanySyncHandler
{
    // private const GEMSUITE_API_URL = 'https://app.gem-books.com/api/';

    // --- CONSTRUCTEUR MIS À JOUR ---
    public function __construct(
        private HttpClientInterface $client,
        private TenantEntityManagerProvider $emProvider,
        private TenantConnectionManager $tenantManager,
        private LoggerInterface $logger,
        private GemsuiteImageUrlBuilder $imageUrlBuilder,
        private TranslationGeneratorService $translationGenerator,
        private string $gemsuiteApiUrl,
        private \Symfony\Component\Messenger\MessageBusInterface $messageBus,
        private SocialNetworkService $socialNetworkService
    ) {}

    /**
     * Gère la mise à jour des informations de l'entreprise.
     */
    public function handleCompanyUpdate(string $tenantCode): void
    {
        $this->logger->info(sprintf('Synchronisation de la configuration de l\'entreprise pour le tenant "%s"', $tenantCode));
        $token = $this->tenantManager->getTenantToken($tenantCode);
        if (!$token) {
            $this->logger->error(sprintf('Aucun token trouvé pour le tenant "%s". Synchronisation annulée.', $tenantCode));
            return;
        }

        try {
            $response = $this->client->request('GET', $this->gemsuiteApiUrl . 'company', [
                'auth_bearer' => $token,
            ]);

            $companyData = $response->toArray()['data'][0] ?? null;
            if (!$companyData) {
                $this->logger->warning(sprintf('Données de l\'entreprise non trouvées sur GEM-SUITE pour le tenant "%s".', $tenantCode));
                return;
            }

            $tenantEm = $this->getTenantEntityManager($tenantCode);

            // OPTIMISATION : On récupère l'entreprise une seule fois
            $entreprise = $tenantEm->getRepository(Entreprise::class)->findOneBy([]) ?? new Entreprise();

            $this->updateEntrepriseData($tenantEm, $entreprise, $companyData);
            $this->updateEmailConfigurationData($tenantEm, $entreprise, $companyData);
            $this->updateHomeSliderData($tenantEm, $entreprise, $companyData);
            $this->updateExploreCardData($tenantEm, $entreprise, $companyData);
            
            $tenantEm->flush();
            $this->logger->info(sprintf('Configuration de l\'entreprise pour le tenant "%s" synchronisée avec succès.', $tenantCode));
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('Erreur lors de la synchronisation de la configuration de l\'entreprise : %s', $e->getMessage()));
        }
    }

    private function updateEntrepriseData(EntityManagerInterface $em, Entreprise $entreprise, array $companyData): void
    {
        // On n'a plus besoin du findOneBy([]) ici car on passe l'objet en paramètre

        $entreprise->setName($companyData['nom'] ?? $entreprise->getName());
        $entreprise->setEmail($companyData['email'] ?? $entreprise->getEmail());
        $entreprise->setTel($companyData['tel'] ?? $entreprise->getTel());
        $entreprise->setWebsite($companyData['website_link'] ?? $entreprise->getWebsite());
        $entreprise->setApropos($companyData['gemportal_about'] ?? null);
        $entreprise->setConditionOfUse($companyData['gemportal_conditions'] ?? null);
        $entreprise->setPrivacyPolicy($companyData['gemportal_politics'] ?? null);
        $entreprise->setLegalNotice($companyData['gemportal_legal'] ?? null);
        $entreprise->setGemsuitePaymentMethodId(isset($companyData['use_gem_payment']) ? (int)$companyData['use_gem_payment'] : $entreprise->getGemsuitePaymentMethodId());

        $identifier = $entreprise->getGemsuiteIdentifier();
        if (isset($companyData['website_link'])) {
            $pathParts = explode('/', rtrim($companyData['website_link'], '/'));
            $newIdentifier = end($pathParts);
            if ($newIdentifier) {
                $entreprise->setGemsuiteIdentifier($newIdentifier);
                $identifier = $newIdentifier;
            }
        }
        $logoPath = $companyData['gemportal_logo'] ?? null;
        $entreprise->setLogo(
            $this->imageUrlBuilder->buildUrl($identifier, $logoPath)
        );
        $faviconPath = $companyData['gemportal_favicon'] ?? null;
        $entreprise->setFaviconFilename(
            $this->imageUrlBuilder->buildUrl($identifier, $faviconPath) ?: null
        );

        if (!empty($companyData['adresse'])) {
            $addressEntreprise = $entreprise->getAddressEntreprise() ?? new AddressEntreprise();
            $addressEntreprise->setStreet1($companyData['adresse']);
            $addressEntreprise->setStreet2('');
            $addressEntreprise->setCity($companyData['ville'] ?? '');
            $addressEntreprise->setState($companyData['prov'] ?? '');
            $addressEntreprise->setZip($companyData['cp'] ?? '');
            $addressEntreprise->setCountry($companyData['country'] == 1 ? 'CA' : 'Unknown');
            $addressEntreprise->setPhone($companyData['tel'] ?? '');
            $addressEntreprise->setEmail($companyData['email'] ?? '');
            $entreprise->setAddressEntreprise($addressEntreprise);
        }

        $this->socialNetworkService->updateSocialNetworksForEntreprise($entreprise, $companyData);

        $em->persist($entreprise);

        // --- AJOUT DE LA TRADUCTION ---
        $this->translationGenerator->generateTranslations($entreprise);
        $this->dispatchTranslationJob($em, $entreprise);
    }

    private function updateEmailConfigurationData(EntityManagerInterface $em, Entreprise $entreprise, array $companyData): void
    {
        $emailConfiguration = $em->getRepository(EmailConfiguration::class)->findOneBy([]) ?? new EmailConfiguration();
        $emailConfiguration->setFromName($companyData['nom'] ?? 'Votre Entreprise');
        $logoPath = $companyData['gemportal_logo'] ?? null;
        $emailConfiguration->setLogo(
            $this->imageUrlBuilder->buildUrl($entreprise->getGemsuiteIdentifier(), $logoPath)
        );
        $em->persist($emailConfiguration);
    }

    private function updateHomeSliderData(EntityManagerInterface $em, Entreprise $entreprise, array $companyData): void
    {
        $existingSliders = $em->getRepository(HomeSlider::class)->findAll();
        foreach ($existingSliders as $slider) {
            $em->remove($slider);
        }

        $identifier = $entreprise->getGemsuiteIdentifier();

        for ($i = 1; $i <= 3; $i++) {
            $bannerKey = 'gemportal_banner' . $i;

            if (!empty($companyData[$bannerKey])) {

                $homeSlider = new HomeSlider();
                $homeSlider->setTitle($companyData['gemportal_title'] ?? 'Bienvenue');
                $homeSlider->setDescription($companyData['gemportal_desc'] ?? 'Découvrez nos produits');
                $homeSlider->setButtonMessage($companyData['gemportal_button'] ?? 'voir nos produit');
                $homeSlider->setButtonUrl('/Product/0');
                $homeSlider->setIsDiplayed(true);

                $homeSlider->setImage(
                    $this->imageUrlBuilder->buildUrl($identifier, $companyData[$bannerKey])
                );
                $em->persist($homeSlider);

                $this->translationGenerator->generateTranslations($homeSlider);
                $this->dispatchTranslationJob($em, $homeSlider);
            }
        }
    }

    private function getTenantEntityManager(string $tenantCode): EntityManagerInterface
    {
        $dbname = 'db_' . $tenantCode;
        $this->emProvider->switchTenant($dbname, $tenantCode);
        return $this->emProvider->getEntityManager();
    }

    /**
     * Dispatch un job de traduction asynchrone pour plus de robustesse.
     */
    private function dispatchTranslationJob(EntityManagerInterface $em, object $entity): void
    {
        try {
            $tenantCode = $this->tenantManager->getCurrentTenantCode();
            if (!$tenantCode) return;

            $tenant = $this->tenantManager->findTenantByCode($tenantCode);
            if (!$tenant) return;

            if (method_exists($entity, 'getTranslatableFields')) {
                $this->messageBus->dispatch(new \App\Message\TranslateEntityJob(
                    (int)$tenant['id'],
                    get_class($entity),
                    $entity->getId()
                ));
            }
        } catch (\Throwable $e) {
            $this->logger->error("Erreur dispatch TranslationJob (CompanySync): " . $e->getMessage());
        }
    }

    private function updateExploreCardData(EntityManagerInterface $em, Entreprise $entreprise, array $companyData): void
    {
        $existingExploreCard = $em->getRepository(ExploreCard::class)->findAll();
        $identifier = $entreprise->getGemsuiteIdentifier();
        foreach ($existingExploreCard as $exploreCard) {
            $em->remove($exploreCard);
        }

        for ($i = 1; $i <= 3; $i++) {
            $baseKey = 'gemportal_features' . $i;

            if (!empty($companyData[$baseKey])) {
                $exploreCard = new ExploreCard();

                $titleKey = $baseKey . '_title';
                $descKey = $baseKey . '_desc';

                $exploreCard->setStandardTitle($companyData[$titleKey] ?? 'Bienvenue');
                $exploreCard->setDescription($companyData[$descKey] ?? 'Découvrez cette fonctionnalité');
                $exploreCard->setIsDifferent(false);

                $imagePath = $companyData[$baseKey] ?? null;
                $exploreCard->setImagePath(
                    $this->imageUrlBuilder->buildUrl($identifier, $imagePath)
                );

                $em->persist($exploreCard);
                $this->translationGenerator->generateTranslations($exploreCard);
                $this->dispatchTranslationJob($em, $exploreCard);
            }
        }
    }


}

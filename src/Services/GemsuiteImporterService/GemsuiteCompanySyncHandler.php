<?php
// src/Services/GemsuiteCompanySyncHandler.php

namespace App\Services\GemsuiteImporterService;

use App\Entity\Entreprise;
use App\Entity\HomeSlider;
use App\Entity\AddressEntreprise;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use App\Services\GemsuiteImporterService\GemsuiteImageUrlBuilder;

class GemsuiteCompanySyncHandler
{
    private const GEMSUITE_API_URL = 'https://app.gem-books.com/api/';

    public function __construct(
        private HttpClientInterface $client,
        private TenantEntityManagerProvider $emProvider,
        private TenantConnectionManager $tenantManager,
        private LoggerInterface $logger,
        private GemsuiteImageUrlBuilder $imageUrlBuilder
    ) {
    }

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
            $response = $this->client->request('GET', self::GEMSUITE_API_URL . 'company', [
                'auth_bearer' => $token,
            ]);

            $companyData = $response->toArray()['data'][0] ?? null;
            if (!$companyData) {
                $this->logger->warning(sprintf('Données de l\'entreprise non trouvées sur GEM-SUITE pour le tenant "%s".', $tenantCode));
                return;
            }
            
            $tenantEm = $this->getTenantEntityManager($tenantCode);
            
            $this->updateEntrepriseData($tenantEm, $companyData);
            $this->updateHomeSliderData($tenantEm, $companyData);

            $tenantEm->flush();
            $this->logger->info(sprintf('Configuration de l\'entreprise pour le tenant "%s" synchronisée avec succès.', $tenantCode));

        } catch (\Throwable $e) {
            $this->logger->error(sprintf('Erreur lors de la synchronisation de la configuration de l\'entreprise : %s', $e->getMessage()));
        }
    }

    private function updateEntrepriseData(EntityManagerInterface $em, array $companyData): void
    {
        $entreprise = $em->getRepository(Entreprise::class)->findOneBy([]) ?? new Entreprise();
        
        $entreprise->setName($companyData['nom'] ?? $entreprise->getName());
        $entreprise->setEmail($companyData['email'] ?? $entreprise->getEmail());
        $entreprise->setTel($companyData['tel'] ?? $entreprise->getTel());
        $entreprise->setWebsite($companyData['website_link'] ?? $entreprise->getWebsite());
        $entreprise->setApropos($companyData['website_about_intro'] ?? $entreprise->getApropos());
        $entreprise->setConditionOfUse($companyData['website_terms'] ?? $entreprise->getConditionOfUse());
        $entreprise->setPrivacyPolicy($companyData['website_conf'] ?? $entreprise->getPrivacyPolicy());


        $identifier = $entreprise->getGemsuiteIdentifier(); 

        if (isset($companyData['website_link'])) {
            $pathParts = explode('/', rtrim($companyData['website_link'], '/'));
            $newIdentifier = end($pathParts);
            if ($newIdentifier) {
                $entreprise->setGemsuiteIdentifier($newIdentifier);
                $identifier = $newIdentifier; 
            }
        }
        $logoPath = $companyData['website_logo1'] ?? null;
        $entreprise->setLogo(
            $this->imageUrlBuilder->buildUrl($identifier, $logoPath)
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
        
        $em->persist($entreprise);
    }

    private function updateHomeSliderData(EntityManagerInterface $em, array $companyData): void
    {
        $homeSlider = $em->getRepository(HomeSlider::class)->findOneBy([]) ?? new HomeSlider();

        $homeSlider->setTitle(strip_tags($companyData['website_intro_text1'] ?? 'Bienvenue'));
        $homeSlider->setDescription(strip_tags($companyData['website_intro_text2'] ?? 'Découvrez nos produits'));
        $homeSlider->setButtonMessage($companyData['website_cta_header'] ?: 'Voir la boutique');
        $homeSlider->setButtonUrl($companyData['website_cta_link'] ?? '/shop');
        $homeSlider->setIsDiplayed(true);
        $identifier = null;
        $entreprise = $em->getRepository(Entreprise::class)->findOneBy([]);
        $identifier = $entreprise ? $entreprise->getGemsuiteIdentifier() : null;
        $bannerPath = $companyData['website_banner'] ?? null;
        $homeSlider->setImage(
            $this->imageUrlBuilder->buildUrl($identifier, $bannerPath)
        );
        
        $em->persist($homeSlider);
    }

    private function getTenantEntityManager(string $tenantCode): EntityManagerInterface
    {
        $dbname = 'db_' . $tenantCode;
        $this->emProvider->switchTenant($dbname, $tenantCode);
        return $this->emProvider->getEntityManager();
    }
}

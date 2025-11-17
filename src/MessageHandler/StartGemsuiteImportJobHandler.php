<?php
// src/MessageHandler/StartGemsuiteImportJobHandler.php

namespace App\MessageHandler;

use App\Entity\AddressEntreprise;
use App\Entity\EmailConfiguration;
use App\Entity\Entreprise;
use App\Entity\HomeSlider;
use App\Entity\SyncJob;
use App\Message\StartGemsuiteImportJob;
use App\Message\ImportGemsuiteCollectionJob;
use App\Services\DefaultAssetSynchronizer;
use App\Services\GemsuiteImporterService\GemsuiteImageUrlBuilder;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use App\Services\TranslationGeneratorService\TranslationGeneratorService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Doctrine\ORM\EntityManagerInterface; 

#[AsMessageHandler]
class StartGemsuiteImportJobHandler
{
    private const GEMSUITE_API_URL = 'https://app.gem-books.com/api/';

    public function __construct(
        private LoggerInterface $logger,
        private TenantConnectionManager $tenantManager,
        private TenantEntityManagerProvider $emProvider,
        private DefaultAssetSynchronizer $assetSynchronizer,
        private TranslationGeneratorService $translationGenerator,
        private GemsuiteImageUrlBuilder $imageUrlBuilder,
        private HttpClientInterface $client,
        private MessageBusInterface $messageBus
    ) {
    }


    public function __invoke(StartGemsuiteImportJob $message)
    {
        $this->logger->info(sprintf(
            '[Job Start] Démarrage du job pour Tenant ID %d (Sync ID: %d)',
            $message->getTenantId(), $message->getSyncJobId() ?? 0
        ));

        $tenant = $this->tenantManager->findTenantById($message->getTenantId());
        
        if (!$tenant) {
            $this->logger->error("[Job Fail] Tenant ID {$message->getTenantId()} non trouvé dans la table 'master'. Arrêt.");
            return;
        }

        $tenantEm = null;
        $syncJob = null;

        try {
            $this->emProvider->switchTenant($tenant['dbname'], $tenant['code']);
            $tenantEm = $this->emProvider->getEntityManager();
            
            $syncJob = $tenantEm->getRepository(SyncJob::class)->find($message->getSyncJobId());
            if (!$syncJob) {
                $this->logger->error("[Job Fail] SyncJob ID {$message->getSyncJobId()} non trouvé pour tenant {$tenant['code']}.");
                return;
            }

            $syncJob->setStatus('running');
            $syncJob->setCurrentStep('Étape 1/3 : Création de la coquille du site...');
            $tenantEm->flush();

            $response = $this->client->request('GET', self::GEMSUITE_API_URL . 'company', [
                'auth_bearer' => $message->getGemsuiteToken(),
            ]);
            $companyData = $response->toArray()['data'][0] ?? null;
            if (!$companyData) {
                throw new \Exception('Aucune donnée d\'entreprise (company) trouvée via l\'API GEM-SUITE.');
            }
            
            // --- Bloc de création de coquille ---
            $this->assetSynchronizer->synchronize($tenantEm);
            
            $entreprise = new Entreprise();
            $entreprise->setName($companyData['nom']);
            $entreprise->setEmail($companyData['email'] ?? null);
            $entreprise->setTel($companyData['tel'] ?? null);
            $entreprise->setTvaIntracommunautaire($companyData['tps'] ?? null); 
            $entreprise->setEin($companyData['federal'] ?? null);
            $entreprise->setApropos($companyData['website_about_intro'] ?? null);
            $entreprise->setConditionOfUse($companyData['website_terms'] ?? null);
            $entreprise->setPrivacyPolicy($companyData['website_conf'] ?? null);

            if (isset($companyData['website_link'])) {
                $pathParts = explode('/', rtrim($companyData['website_link'], '/'));
                $identifier = end($pathParts);
                $entreprise->setGemsuiteIdentifier($identifier);
            }
            
            $logoPath = $companyData['website_logo1'] ?? null;
            $entreprise->setLogo(
                $this->imageUrlBuilder->buildUrl($entreprise->getGemsuiteIdentifier(), $logoPath)
            );
          
            if ($companyData && !empty($companyData['adresse'])) {
                $addressEntreprise = new AddressEntreprise();
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
            $tenantEm->persist($entreprise);

            $this->translationGenerator->generateTranslations($entreprise);

            if($companyData){
                $emailConfiguration = $tenantEm->getRepository(EmailConfiguration::class)->findOneBy([]) ?? new EmailConfiguration();
                $emailConfiguration->setFromName($companyData['nom'] ?? 'Votre Entreprise');
                $logoPath = $companyData['website_logo1'] ?? null;
                $emailConfiguration->setLogo(
                    $this->imageUrlBuilder->buildUrl($entreprise->getGemsuiteIdentifier(), $logoPath)
                );
                $tenantEm->persist($emailConfiguration); 
            }

            if ($companyData) {
                $homeSlider = new HomeSlider();
                $homeSlider->setTitle(strip_tags($companyData['website_intro_text1'] ?? 'Bienvenue'));
                $homeSlider->setDescription(strip_tags($companyData['website_intro_text2'] ?? 'Découvrez nos produits'));
                $homeSlider->setButtonMessage('Voir la boutique');
                $homeSlider->setButtonUrl('/shop');
                $homeSlider->setIsDiplayed(true);

                $bannerPath = $companyData['website_banner'] ?? null;
                $homeSlider->setImage(
                    $this->imageUrlBuilder->buildUrl($entreprise->getGemsuiteIdentifier(), $bannerPath)
                );
                $tenantEm->persist($homeSlider);

                $this->translationGenerator->generateTranslations($homeSlider);
            }

            $tenantEm->flush();
            // --- Fin du bloc coquille ---

            $this->logger->info(sprintf(
                '[Job Info] Coquille créée pour %s. Prochaine étape : import des données.',
                $companyData['nom']
            ));

            $syncJob->setCurrentStep('Étape 2/3 : Lancement de l\'importation...');
            $tenantEm->flush();

            // On dispatche le premier job de la chaîne de montage
            $this->messageBus->dispatch(new ImportGemsuiteCollectionJob(
                $message->getTenantId(),
                $message->getGemsuiteToken(),
                $message->getSyncJobId(),
                'clients_contacts',
                1 
            ));

            $this->logger->info(sprintf(
                '[Job Info] Premier job de la chaîne (clients_contacts) dispatché pour Tenant ID %d',
                $message->getTenantId()
            ));

            $this->logger->info(sprintf(
                '[Job Success] Le "Chef de Chantier" (StartGemsuiteImportJobHandler) a fini sa mission pour le Tenant ID %d',
                $message->getTenantId()
            ));

        } catch (\Throwable $e) {
            $this->logger->error('[Job Fail] Erreur critique durant l\'exécution du job: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            
            if (isset($syncJob) && $tenantEm instanceof EntityManagerInterface) {
                $syncJob->setStatus('failed');
                $syncJob->setLastError(substr($e->getMessage(), 0, 500));
                $tenantEm->flush();
            }
        }
    }
}
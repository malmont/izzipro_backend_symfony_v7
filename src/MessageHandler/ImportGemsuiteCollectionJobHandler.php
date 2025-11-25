<?php
// src/MessageHandler/ImportGemsuiteCollectionJobHandler.php

namespace App\MessageHandler;

use App\Entity\Entreprise;
use App\Entity\SyncJob;
use App\Message\ImportGemsuiteCollectionJob;
use App\Message\ProcessGemsuiteEntityJob;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use App\Message\FinalizeSyncJob;

#[AsMessageHandler]
class ImportGemsuiteCollectionJobHandler
{
    // private const GEMSUITE_API_URL = 'https://app.gem-books.com/api/';
    private const PAGE_LIMIT = 50;

    private const IMPORT_CHAIN = [
        'clients_contacts',
        'categories',
        'products',
    ];

    public function __construct(
        private LoggerInterface $logger,
        private TenantConnectionManager $tenantManager,
        private TenantEntityManagerProvider $emProvider,
        private MessageBusInterface $messageBus,
        private HttpClientInterface $client,
        private string $gemsuiteApiUrl
    ) {
    }

    public function __invoke(ImportGemsuiteCollectionJob $message)
    {
        $type = $message->getCollectionType();
        $page = $message->getPage();
        
        $this->logger->info(sprintf(
            '[Job Paginé Start] Reçu collection "%s", page %d pour Tenant ID %d',
            $type, $page, $message->getTenantId()
        ));

        $tenant = $this->tenantManager->findTenantById($message->getTenantId());
        if (!$tenant) {
            $this->logger->error("[Job Paginé Fail] Tenant ID {$message->getTenantId()} non trouvé.");
            return;
        }

        $tenantEm = null;
        $syncJob = null;

        try {
            $this->emProvider->switchTenant($tenant['dbname'], $tenant['code']);
            $tenantEm = $this->emProvider->getEntityManager();
            $syncJob = $tenantEm->getRepository(SyncJob::class)->find($message->getSyncJobId());
            if (!$syncJob) { throw new \Exception("SyncJob non trouvé"); }

            $items = [];
            $itemCount = 0;
            $isLastPage = false;
            
            if ($type === 'categories') {
                $this->logger->warning(sprintf('Collection "%s" non paginée. Tentative de "Big Dump".', $type));
                $response = $this->client->request('GET', $this->gemsuiteApiUrl . $type, [
                    'auth_bearer' => $message->getGemsuiteToken(),
                ]);
                $data = $response->toArray();
                $items = $data['data'] ?? []; // Sécurité
                $itemCount = count($items);
                $isLastPage = true;

                $syncJob->setTotalItems($syncJob->getTotalItems() + $itemCount);

            } else {
                $response = $this->client->request('GET', $this->gemsuiteApiUrl . $type, [
                    'auth_bearer' => $message->getGemsuiteToken(),
                    'query' => [
                        'page' => $page,
                        'per_page' => self::PAGE_LIMIT
                    ]
                ]);
            
                $data = $response->toArray();
                if (!isset($data['data'])) { throw new \Exception("Clé 'data' manquante de l'API {$type}"); }

                $items = $data['data'];
                $itemCount = count($items);
                
                if ($page === 1 && isset($data['meta']['total'])) {
                    $totalFromApi = (int)$data['meta']['total'];
                    $syncJob->setTotalItems($syncJob->getTotalItems() + $totalFromApi);
                }
                
                if (isset($data['meta']['current_page']) && isset($data['meta']['last_page'])) {
                    $isLastPage = ((int)$data['meta']['current_page'] === (int)$data['meta']['last_page']);
                } else {
                    $isLastPage = ($itemCount < self::PAGE_LIMIT);
                }
            }
            
            $tenantEm->flush();
            $microJobType = ($type === 'clients_contacts') ? 'client' : $type;

            if ($microJobType === 'products') {
                $this->dispatchProductJobs($items, $message);
            } else {
                $this->dispatchSimpleJobs($items, $microJobType, $message);
            }

            if ($isLastPage) {
                $this->logger->info(sprintf('Fin de la collection "%s".', $type));
                $this->dispatchNextJob($type, $message); 
            } else {
                $this->logger->info(sprintf('Page %d de "%s" traitée. Demande de la page %d.', $page, $type, $page + 1));
                $this->messageBus->dispatch(new ImportGemsuiteCollectionJob(
                    $message->getTenantId(),
                    $message->getGemsuiteToken(),
                    $message->getSyncJobId(),
                    $type,
                    $page + 1
                ));
            }

        } catch (\Throwable $e) {
            $this->logger->error("[Job Paginé Fail] Erreur sur '{$type}' Page {$page}: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            if (isset($syncJob)) {
                $syncJob->setStatus('failed');
                $syncJob->setLastError("Échec {$type}: " . $e->getMessage());
                $tenantEm->flush();
            }
        }
    }
    
    

    /**
     * Pour les jobs simples (clients, categories)
     */
    private function dispatchSimpleJobs(array $items, string $type, ImportGemsuiteCollectionJob $originalMessage): void
    {
        foreach ($items as $itemData) {
            $this->messageBus->dispatch(new ProcessGemsuiteEntityJob(
                $originalMessage->getTenantId(),
                $originalMessage->getSyncJobId(),
                $type,
                $itemData
            ));
        }
        $this->logger->info(sprintf('Dispatch de %d micro-jobs pour "%s"', count($items), $type));
    }

    /**
     * Pour le cas complexe des produits (2-Pass)
     */
    private function dispatchProductJobs(array $items, ImportGemsuiteCollectionJob $originalMessage): void
    {
        $this->logger->info('Dispatch des produits : Passe 1 (Parents)');
        foreach ($items as $itemData) {
            if ($itemData['id'] === $itemData['origin_product_id']) {
                $this->messageBus->dispatch(new ProcessGemsuiteEntityJob(
                    $originalMessage->getTenantId(),
                    $originalMessage->getSyncJobId(),
                    'product_parent',
                    $itemData
                ));
            }
        }
        
        $this->logger->info('Dispatch des produits : Passe 2 (Variantes)');
        foreach ($items as $itemData) {
             $this->messageBus->dispatch(new ProcessGemsuiteEntityJob(
                $originalMessage->getTenantId(),
                $originalMessage->getSyncJobId(),
                'product_variant',
                $itemData
            ));
        }
    }

   /**
     * Gère la "chaîne de montage"
     */
    private function dispatchNextJob(string $currentType, ImportGemsuiteCollectionJob $originalMessage): void
    {
        $currentIndex = array_search($currentType, self::IMPORT_CHAIN);
        $nextJobType = ($currentIndex !== false && $currentIndex < count(self::IMPORT_CHAIN) - 1)
            ? self::IMPORT_CHAIN[$currentIndex + 1]
            : null;

        if ($nextJobType) {
            $this->logger->info(sprintf('Dispatch du job suivant : "%s"', $nextJobType));
            $this->messageBus->dispatch(new ImportGemsuiteCollectionJob(
                $originalMessage->getTenantId(),
                $originalMessage->getGemsuiteToken(),
                $originalMessage->getSyncJobId(),
                $nextJobType,
                1 
            ));
        } else {
            $this->logger->info(sprintf('Fin de la chaîne d\'importation ("%s" était le dernier). Lancement du FINAL.', $currentType));
            $this->messageBus->dispatch(new FinalizeSyncJob(
                $originalMessage->getTenantId(),
                $originalMessage->getSyncJobId()
            ));
        }
    }
}
<?php
// src/MessageHandler/ImportGemsuiteCollectionJobHandler.php

namespace App\MessageHandler;

use App\Entity\Entreprise;
use App\Entity\SyncJob;
use App\Message\CollectionDoneBarrierJob;
use App\Message\ImportGemsuiteCollectionJob;
use App\Message\ProcessGemsuiteEntityJob;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsMessageHandler]
class ImportGemsuiteCollectionJobHandler
{
    // private const GEMSUITE_API_URL = 'https://app.gem-books.com/api/';
    private const PAGE_LIMIT = 50;

    private const IMPORT_CHAIN = [
        'clients_contacts',
        'categories',
        'company_config',
        'resources',
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

            if ($type === 'company_config') {
                $this->messageBus->dispatch(new ProcessGemsuiteEntityJob(
                    $message->getTenantId(),
                    $message->getSyncJobId(),
                    'company_config',
                    []
                ));
                // La barrière garantit que le job company_config est traité
                // avant de déclencher la collection suivante.
                $this->dispatchBarrier($type, $message);
                return;
            }

            $items = [];
            $itemCount = 0;
            $isLastPage = false;
            
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
            
            $tenantEm->flush();
            $microJobType = ($type === 'clients_contacts') ? 'client' : $type;

            if ($microJobType === 'products') {
                $this->dispatchProductJobs($items, $message);
            } else {
                $this->dispatchSimpleJobs($items, $microJobType, $message);
            }

            if ($isLastPage) {
                // Fin de la collection : on dispatch la BARRIÈRE dans le même stream Redis.
                // Le CollectionDoneBarrierJobHandler sera exécuté par le worker APRÈS tous
                // les micro-jobs de cette collection (garantie FIFO de Redis Streams),
                // et c'est lui qui déclenchera la collection suivante.
                $this->logger->info(sprintf('[Barrier] Dispatch du CollectionDoneBarrierJob pour "%s".', $type));
                $this->dispatchBarrier($type, $message);
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
     * Dispatche un message CollectionDoneBarrierJob dans le même stream Redis que les micro-jobs.
     *
     * Grâce au TenantRoutingMiddleware (routing basé sur getTenantId()),
     * ce message barrière sera envoyé dans le même async_worker_X que tous
     * les micro-jobs de la collection. Redis Streams garantit l'ordre FIFO,
     * donc le barrière sera traité en dernier, après tous les micro-jobs.
     */
    private function dispatchBarrier(string $currentType, ImportGemsuiteCollectionJob $originalMessage): void
    {
        $this->messageBus->dispatch(new CollectionDoneBarrierJob(
            $originalMessage->getTenantId(),
            $originalMessage->getGemsuiteToken(),
            $originalMessage->getSyncJobId(),
            $currentType
        ));
    }
}
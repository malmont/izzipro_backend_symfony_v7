<?php
// src/Services/GemsuiteImporterService/GemsuiteClientManager.php

namespace App\Services\GemsuiteImporterService;

use App\Entity\GemsuiteClient;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider; 
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class GemsuiteClientManager
{
    private const GEMSUITE_API_URL = 'https://app.gem-books.com/api/';


    public function __construct(
        private HttpClientInterface $client,
        private TenantConnectionManager $tenantManager,
        private TenantEntityManagerProvider $emProvider,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Cherche un client par email dans la DB locale. S'il n'est pas trouvé, 
     * le crée sur GEM-SUITE puis le sauvegarde localement.
     *
     * @return GemsuiteClient|null L'entité locale trouvée ou créée.
     */
    public function findOrCreateClient(string $email, string $firstName, string $lastName, string $tenantCode): ?GemsuiteClient
    {
        try {
            $tenantEm = $this->getTenantEntityManager($tenantCode);

            $localClient = $tenantEm->getRepository(GemsuiteClient::class)->findOneBy(['email' => strtolower($email)]);
            if ($localClient) {
                $this->logger->info(sprintf('Client trouvé localement pour l\'email "%s" (ID Gemsuite: %d).', $email, $localClient->getGemsuiteId()));
                return $localClient;
            }

            $this->logger->info(sprintf('Client non trouvé localement pour l\'email "%s". Tentative de création sur GEM-SUITE.', $email));
            
            $token = $this->tenantManager->getTenantToken($tenantCode);
            if (!$token) {
                $this->logger->warning(sprintf('Aucun token pour le tenant "%s".', $tenantCode));
                return null;
            }
            
            $newClientData = $this->createClientOnGemsuite($email, $firstName, $lastName, $token);

            if ($newClientData) {
                $newLocalClient = new GemsuiteClient();
                $newLocalClient->setGemsuiteId($newClientData['id']);
                $newLocalClient->setEmail(strtolower($newClientData['email'])); 
                $newLocalClient->setName($newClientData['name']);
                
                $tenantEm->persist($newLocalClient);
                $tenantEm->flush();
                
                $this->logger->info(sprintf('Client créé sur GEM-SUITE (ID: %d) et synchronisé localement.', $newClientData['id']));
                return $newLocalClient;
            }

        } catch (\Throwable $e) {
            $this->logger->error('Erreur de communication avec GEM-SUITE lors de la gestion du client : ' . $e->getMessage());
        }

        return null;
    }
    
    /**
     * Crée un client sur GEM-SUITE via un appel API POST.
     * (Anciennement "createClient")
     */
    private function createClientOnGemsuite(string $email, string $firstName, string $lastName, string $token): ?array
    {
        $response = $this->client->request('POST', self::GEMSUITE_API_URL . 'clients', [
            'auth_bearer' => $token,
            'json' => [
                'name' => $firstName . ' ' . $lastName,
                'code' => 'IIZIPRO_' . strtoupper(substr($firstName, 0, 1) . substr($lastName, 0, 1)) . time(),
                'email' => $email,
            ]
        ]);

        if ($response->getStatusCode() === 200 || $response->getStatusCode() === 201) {
            return $response->toArray()['data'] ?? null;
        }
        
        $this->logger->error('La création du client sur GEM-SUITE a échoué.', [
            'status_code' => $response->getStatusCode(),
            'response' => $response->getContent(false)
        ]);

        return null;
    }

    /**
     * Récupère l'EntityManager pour un tenant donné.
     */
    private function getTenantEntityManager(string $tenantCode): EntityManagerInterface
    {
        $dbname = 'db_' . $tenantCode;
        $this->emProvider->switchTenant($dbname, $tenantCode);
        return $this->emProvider->getEntityManager();
    }
}
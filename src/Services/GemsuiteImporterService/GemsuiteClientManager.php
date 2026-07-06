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
    // private const GEMSUITE_API_URL = 'https://app.gem-books.com/api/';


    public function __construct(
        private HttpClientInterface $client,
        private TenantConnectionManager $tenantManager,
        private TenantEntityManagerProvider $emProvider,
        private LoggerInterface $logger,
        private string $gemsuiteApiUrl
    ) {
    }

    /**
     * Cherche un client par email dans la DB locale. S'il n'est pas trouvé, 
     * le crée sur GEM-SUITE puis le sauvegarde localement.
     *
     * @return GemsuiteClient|null L'entité locale trouvée ou créée.
     */
    public function findOrCreateClient(string $email, string $firstName, string $lastName, string $tenantCode, bool $isProspect = false): ?GemsuiteClient
    {
        try {
            $tenantEm = $this->getTenantEntityManager($tenantCode);

            $localClient = $tenantEm->getRepository(GemsuiteClient::class)->findOneBy(['email' => strtolower($email)]);
            if ($localClient) {
                $this->logger->info(sprintf('Client trouvé localement pour l\'email "%s" (ID Gemsuite: %d).', $email, $localClient->getGemsuiteId()));
                if ($isProspect) {
                    $token = $this->tenantManager->getTenantToken($tenantCode);
                    if ($token) {
                        $this->updateClientProspectStatus($localClient->getGemsuiteId(), $token, true);
                    } else {
                        $this->logger->warning(sprintf('Aucun token pour le tenant "%s", impossible de mettre à jour le statut prospect.', $tenantCode));
                    }
                }
                return $localClient;
            }

            $token = $this->tenantManager->getTenantToken($tenantCode);
            if (!$token) {
                $this->logger->warning(sprintf('Aucun token pour le tenant "%s".', $tenantCode));
                return null;
            }

            $this->logger->info(sprintf('Client non trouvé localement pour l\'email "%s". Tentative de création sur GEM-SUITE.', $email));
            
            $newClientData = $this->createClientOnGemsuite($email, $firstName, $lastName, $token, $isProspect);

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
    private function createClientOnGemsuite(string $email, string $firstName, string $lastName, string $token, bool $isProspect = false): ?array
    {
        $jsonPayload = [
            'name' => $firstName . ' ' . $lastName,
            'code' => 'IIZIPRO_' . strtoupper(substr($firstName, 0, 1) . substr($lastName, 0, 1)) . time(),
            'email' => $email,
        ];

        if ($isProspect) {
            $jsonPayload['prospect'] = 1;
        }

        $response = $this->client->request('POST', $this->gemsuiteApiUrl . 'clients', [
            'auth_bearer' => $token,
            'json' => $jsonPayload
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

    private function updateClientProspectStatus(int $gemsuiteClientId, string $token, bool $isProspect): void
    {
        try {
            $this->client->request('PUT', $this->gemsuiteApiUrl . 'clients/' . $gemsuiteClientId, [
                'auth_bearer' => $token,
                'json' => [
                    'prospect' => $isProspect ? 1 : 0
                ]
            ]);
            $this->logger->info(sprintf('Statut prospect mis à jour pour le client #%d.', $gemsuiteClientId));
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('Impossible de mettre à jour le statut prospect du client #%d : %s', $gemsuiteClientId, $e->getMessage()));
        }
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

    public function updateClientGemsuite(string $tenantCode, int $clientId): void
    {
        $tenantEm = $this->getTenantEntityManager($tenantCode);
        $clientGemSuite = $this->getClientFromGemsuite($clientId, $tenantCode);

        if (!$clientGemSuite) {
            $this->logger->warning(sprintf('Client #%d non trouvé sur GEM-SUITE. Impossible de synchroniser.', $clientId));
            return;
        }

        $client = $tenantEm->getRepository(GemsuiteClient::class)->findOneBy(['gemsuiteId' => $clientId]);
        $isNew = false;

        if (!$client) {
            $this->logger->info(sprintf('Client #%d non trouvé localement. Création en cours...', $clientId));
            $client = new GemsuiteClient();
            $client->setGemsuiteId($clientGemSuite['id']);
            $tenantEm->persist($client);
            $isNew = true;
        }

        // Mise à jour des données
        $client->setEmail(isset($clientGemSuite['email']) ? strtolower($clientGemSuite['email']) : $client->getEmail());
        $client->setName($clientGemSuite['name'] ?? $client->getName());

        $tenantEm->flush();

        if ($isNew) {
            $this->logger->info(sprintf('Client #%d importé avec succès depuis GEM-SUITE.', $clientId));
        } else {
            $this->logger->info(sprintf('Client #%d mis à jour avec succès depuis GEM-SUITE.', $clientId));
        }
    }

    private function getClientFromGemsuite(int $clientId, string $tenantCode): ?array
    {
        $token = $this->tenantManager->getTenantToken($tenantCode);
        if (!$token) {
            $this->logger->warning(sprintf('Aucun token pour le tenant "%s".', $tenantCode));
            return null;
        }
        $response = $this->client->request('GET', $this->gemsuiteApiUrl . 'clients/' . $clientId, [
            'auth_bearer' => $token,
        ]);

        if ($response->getStatusCode() === 200 || $response->getStatusCode() === 201) {
            return $response->toArray()['data'] ?? null;
        }
        
        $this->logger->error('La récupération du client sur GEM-SUITE a échoué.', [
            'status_code' => $response->getStatusCode(),
            'response' => $response->getContent(false)
        ]);

        return null;
    }
}
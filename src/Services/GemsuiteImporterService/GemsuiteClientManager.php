<?php


namespace App\Services\GemsuiteImporterService;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use App\Services\TenantConnectionManager;

class GemsuiteClientManager
{
    private const GEMSUITE_API_URL = 'https://app.gem-books.com/api/';

    public function __construct(
        private HttpClientInterface $client,
        private TenantConnectionManager $tenantManager,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Cherche un client par email dans GEM-SUITE. S'il n'est pas trouvé, le crée.
     *
     * @return array|null Les données du client trouvé ou créé, ou null en cas d'erreur.
     */
    public function findOrCreateClient(string $email, string $firstName, string $lastName, string $tenantCode): ?array
    {
        $token = $this->tenantManager->getTenantToken($tenantCode);
        if (!$token) {
            $this->logger->warning(sprintf('Aucun token pour le tenant "%s", impossible de synchroniser le client.', $tenantCode));
            return null;
        }

        try {

            $foundClient = $this->findClientByEmail($email, $token);

            if ($foundClient === null) {
                $this->logger->info(sprintf('Client non trouvé pour l\'email "%s". Tentative de création.', $email));
                $foundClient = $this->createClient($email, $firstName, $lastName, $token);
            }

            return $foundClient;

        } catch (\Throwable $e) {
            $this->logger->error('Erreur de communication avec GEM-SUITE lors de la gestion du client : ' . $e->getMessage());
            return null;
        }
    }

    private function findClientByEmail(string $email, string $token): ?array
    {
        $response = $this->client->request('GET', self::GEMSUITE_API_URL . 'clients', [
            'auth_bearer' => $token,
        ]);

        $clients = $response->toArray()['data'] ?? [];

        foreach ($clients as $client) {
            if (isset($client['email']) && strtolower($client['email']) === strtolower($email)) {
                return $client;
            }
            if (isset($client['contacts']) && is_array($client['contacts'])) {
                foreach ($client['contacts'] as $contact) {
                    if (isset($contact['email']) && strtolower($contact['email']) === strtolower($email)) {
                        return $client;
                    }
                }
            }
        }

        return null;
    }

    private function createClient(string $email, string $firstName, string $lastName, string $token): ?array
    {
        $newClientResponse = $this->client->request('POST', self::GEMSUITE_API_URL . 'clients', [
            'auth_bearer' => $token,
            'json' => [
                'name' => $firstName . ' ' . $lastName,
                'code' => 'IIZIPRO_' . strtoupper(substr($firstName, 0, 1) . substr($lastName, 0, 1)) . time(),
                'email' => $email,
            ]
        ]);

        if ($newClientResponse->getStatusCode() === 200 || $newClientResponse->getStatusCode() === 201) {
            return $newClientResponse->toArray()['data'] ?? null;
        }

        return null;
    }
}

<?php
// src/Services/GemsuiteImporterService/GemsuiteClientUpdater.php

namespace App\Services\GemsuiteImporterService;

use App\Entity\Adress;
use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use App\Services\TenantConnectionManager;

class GemsuiteClientUpdater
{
    private const GEMSUITE_API_URL = 'https://app.gem-books.com/api/';

    public function __construct(
        private HttpClientInterface $client,
        private TenantConnectionManager $tenantManager,
        private LoggerInterface $logger
    ) {
    }


    public function syncAddress(User $user, Adress $address): void
    {
        $gemsuiteClient = $user->getGemsuiteClient();

        if (!$gemsuiteClient) {
            $this->logger->info(sprintf('L\'utilisateur %s n\'a pas de client GEM-SUITE associé. Aucune synchronisation d\'adresse effectuée.', $user->getEmail()));
            return;
        }
        $gemsuiteClientId = $gemsuiteClient->getGemsuiteId();
        $tenantCode = $this->tenantManager->getCurrentTenantCode();
        $token = $this->tenantManager->getTenantToken($tenantCode);
        if (!$token) {
            $this->logger->warning(sprintf('Aucun token pour le tenant "%s", impossible de synchroniser l\'adresse.', $tenantCode));
            return;
        }

        try {
            $this->logger->info(sprintf('Synchronisation de l\'adresse pour le client GEM-SUITE #%d', $gemsuiteClientId));

            $payload = [
                'address' => $address->getAddress(),
                'address2' => $address->getComplement(),
                'city' => $address->getCity(),
                'state' => $address->getProvince(),
                'zipcode' => $address->getCodepostal(),
                'phone' => $address->getPhone(),
                'pays' => $address->getCountry(),
            ];

            $this->client->request('PUT', self::GEMSUITE_API_URL . 'clients/' . $gemsuiteClientId, [
                'auth_bearer' => $token,
                'json' => $payload,
            ]);

            $this->logger->info(sprintf('Adresse pour le client GEM-SUITE #%d synchronisée avec succès.', $gemsuiteClientId));

        } catch (\Throwable $e) {
            $this->logger->error('Erreur lors de la synchronisation de l\'adresse vers GEM-SUITE : ' . $e->getMessage());
        }
    }
}
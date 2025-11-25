<?php
// src/Services/GemsuiteImporterService/GemsuiteImporter.php

namespace App\Services\GemsuiteImporterService;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class GemsuiteImporter
{
    // private const GEMSUITE_API_URL = 'https://app.gem-books.com/api/';

    public function __construct(
        private HttpClientInterface $client,
        private LoggerInterface $logger,
        private string $gemsuiteApiUrl
    ) {
    }

    public function checkPrerequisites(string $token): void
    {
        $this->logger->info('Début de la pré-vérification des données GEM-SUITE.');
        $response = $this->client->request('GET', $this->gemsuiteApiUrl . 'categories', [
            'auth_bearer' => $token,
        ]);
        
        $data = $response->toArray();
        
        if (empty($data['data'])) {
            $this->logger->error('Pré-vérification échouée : Aucune catégorie retournée par l\'API GEM-SUITE.');
            throw new \Exception('Aucune catégorie trouvée sur GEM-SUITE. L\'importation ne peut pas être lancée.');
        }
        $this->logger->info('Pré-vérification des données GEM-SUITE réussie.');
    }
}
<?php
// src/Services/ShippingService/EasyPostService.php

namespace App\Services\ShippingService;

use App\Entity\EasyPostConfiguration; 
use App\Services\TenantEntityManagerProvider;
use EasyPost\EasyPostClient;
use LogicException;

class EasyPostService
{
    // MODIFICATION 1 : Le client n'est plus une propriété.
    // Ou alors on le met à `private ?EasyPostClient $client = null;` pour du caching interne.
    // Pour la simplicité, nous allons le créer à chaque fois.
    
    // MODIFICATION 2 : On injecte notre provider.
    private TenantEntityManagerProvider $emProvider;

    public function __construct(TenantEntityManagerProvider $emProvider)
    {
        $this->emProvider = $emProvider;
    }

    /**
     * MODIFICATION 3 : Nouvelle méthode privée pour créer le client à la demande.
     * C'est elle qui contient maintenant la logique qui était dans le constructeur.
     */
    private function getTenantClient(): EasyPostClient
    {
        $em = $this->emProvider->getEntityManager();
        $repo = $em->getRepository(EasyPostConfiguration::class);
        $config = $repo->findOneBy([]);

        if (!$config || !$config->getEasypostApiKeySandbox()) {
            throw new LogicException('Clé API EasyPost manquante pour ce tenant.');
        }

        return new EasyPostClient($config->getEasypostApiKeySandbox());
    }

    public function getRates(array $data): array
    {
        // MODIFICATION 4 : On récupère le client spécifique au tenant avant de l'utiliser.
        $client = $this->getTenantClient();
        return $client->shipment->create($data)->rates;
    }

    public function createShipmentAndBuy(array $data, string $carrierAccountId, string $service): array
    {
        // On récupère le client spécifique au tenant.
        $client = $this->getTenantClient();

        $shipment = $client->shipment->create($data);
        
        // ... la suite de la logique est inchangée...
        $rateToBuy = null;
        foreach ($shipment->rates as $r) {
            if ($r->carrier_account_id === $carrierAccountId && $r->service === $service) {
                $rateToBuy = $r;
                break;
            }
        }
        if (!$rateToBuy) {
            $rateToBuy = $shipment->lowestRate();
        }

        $bought = $client->shipment->buy($shipment->id, ['rate' => $rateToBuy]);

        return [
            'label_url'     => $bought->postage_label->label_url,
            'tracking_code' => $bought->tracking_code,
        ];
    }

    public function track(string $code): array
    {
        // On récupère le client spécifique au tenant.
        $client = $this->getTenantClient();
        return $client->tracker->create(['tracking_code' => $code])->status_history;
    }
}
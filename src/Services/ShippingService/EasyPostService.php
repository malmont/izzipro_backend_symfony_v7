<?php
// src/Services/ShippingService/EasyPostService.php

namespace App\Services\ShippingService;

use App\Entity\EasyPostConfiguration; 
use App\Services\TenantEntityManagerProvider;
use EasyPost\EasyPostClient;
use LogicException;

class EasyPostService
{

    private TenantEntityManagerProvider $emProvider;

    public function __construct(TenantEntityManagerProvider $emProvider)
    {
        $this->emProvider = $emProvider;
    }


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
    public function getClient(): EasyPostClient
    {
        return $this->getTenantClient();
    }

    public function getRates(array $data): array
    {
        $client = $this->getTenantClient();
        return $client->shipment->create($data)->rates;
    }

    public function createShipmentAndBuy(array $data, string $carrierAccountId, string $service): array
    {

        $client = $this->getTenantClient();

        $shipment = $client->shipment->create($data);
        
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
        $client = $this->getTenantClient();
        return $client->tracker->create(['tracking_code' => $code])->status_history;
    }
}
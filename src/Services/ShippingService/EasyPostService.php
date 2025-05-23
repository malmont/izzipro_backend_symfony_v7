<?php
// src/Services/ShippingService/EasyPostService.php

namespace App\Services\ShippingService;

use App\Repository\EasyPostConfigurationRepository;
use EasyPost\EasyPostClient;
use LogicException;

class EasyPostService
{
    private EasyPostClient $client;

    public function __construct(EasyPostConfigurationRepository $repo)
    {
        $config = $repo->findOneBy([]);
        if (!$config || !$config->getEasypostApiKeySandbox()) {
            throw new LogicException('Clé EasyPost manquante en base.');
        }
        $this->client = new EasyPostClient($config->getEasypostApiKeySandbox());
    }

    public function getRates(array $data): array
    {
        return $this->client->shipment->create($data)->rates;
    }

    /**
     * Crée un shipment puis achète le label.
     *
     * @param array  $data              // mêmes clés que pour getRates()
     * @param string $carrierAccountId  // ex "ca_…"
     * @param string $service           // ex "Priority"
     * @return array ['label_url'=>…, 'tracking_code'=>…]
     */
    public function createShipmentAndBuy(array $data, string $carrierAccountId, string $service): array
    {
        // 1) création
        $shipment = $this->client->shipment->create($data);
        

        // 2) on cherche le rate qui correspond
        $rateToBuy = null;
        foreach ($shipment->rates as $r) {
            if ($r->carrier_account_id === $carrierAccountId && $r->service === $service) {
                $rateToBuy = $r;
                break;
            }
        }
        // fallback sur le moins cher si introuvable
        if (! $rateToBuy) {
            $rateToBuy = $shipment->lowestRate();
        }

        // 3) on achète
        // Note : la signature est buy($id, ['rate'=>$rateObject])
        $bought = $this->client->shipment->buy($shipment->id, ['rate' => $rateToBuy]);

        return [
            'label_url'     => $bought->postage_label->label_url,
            'tracking_code' => $bought->tracking_code,
        ];
    }

    public function track(string $code): array
    {
        return $this->client->tracker->create(['tracking_code' => $code])->status_history;
    }
}

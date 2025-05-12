<?php
namespace App\Services\ShippingService;

use App\Repository\EasyPostConfigurationRepository;
use EasyPost\EasyPostClient;

class EasyPostService
{
    private EasyPostClient $client;

    public function __construct(EasyPostConfigurationRepository $repo)
    {
        $config = $repo->findOneBy([]);
        if (!$config) {
            throw new LogicException('Aucune configuration EasyPost trouvée : merci d’en créer une via EasyAdmin.');
        }
        $key = $config->getEasypostApiKeySandbox();
        if (!$key) {
            throw new LogicException('La clé sandbox EasyPost est vide. Merci de la renseigner.');
        }
        $this->client = new EasyPostClient($key);
    }

    public function getRates(array $data): array
    {
        return $this->client->shipment->create($data)->rates;
    }

    public function createShipment(array $data): array
    {
        $s = $this->client->shipment->createAndBuy($data);
        return [
            'label_url'     => $s->postage_label->label_url,
            'tracking_code' => $s->tracking_code,
        ];
    }
    
    public function track(string $code): array
    {
        return $this->client->tracker->create(['tracking_code' => $code])->status_history;
    }
}

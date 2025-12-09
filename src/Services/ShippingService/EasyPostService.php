<?php
// src/Services/ShippingService/EasyPostService.php

namespace App\Services\ShippingService;

use App\Entity\EasyPostConfiguration;
use App\Services\TenantEntityManagerProvider;
use EasyPost\EasyPostClient;
use LogicException;
use RuntimeException;

class EasyPostService
{
    private TenantEntityManagerProvider $emProvider;
    private string $appEnv;
    public function __construct(
        TenantEntityManagerProvider $emProvider,
        string $appEnv
    ) {
        $this->emProvider = $emProvider;
        $this->appEnv = $appEnv;
    }

    protected function getTenantClient(): EasyPostClient
    {
        $em = $this->emProvider->getEntityManager();
        $repo = $em->getRepository(EasyPostConfiguration::class);
        $config = $repo->findOneBy([]);

        if (!$config) {
            throw new LogicException('Aucune configuration EasyPost trouvée pour ce tenant.');
        }

        $apiKey = null;

        if ($this->appEnv === 'prod') {
            try {
                $apiKey = $config->getEasypostApiKeyProd();
                if (!$apiKey) {
                    throw new LogicException('Clé API EasyPost de PRODUCTION manquante ou invalide pour ce tenant.');
                }
            } catch (RuntimeException $e) {
                throw new LogicException('Erreur lors du déchiffrement de la clé API EasyPost Production: ' . $e->getMessage(), 0, $e);
            }
        } else {
            $apiKey = $config->getEasypostApiKeySandbox();
            if (!$apiKey) {
                throw new LogicException('Clé API EasyPost SANDBOX manquante pour ce tenant.');
            }
        }

        return new EasyPostClient($apiKey);
    }


    public function getClient(): EasyPostClient
    {
        return $this->getTenantClient();
    }

    public function getRates(array $data): array
    {
        $client = $this->getTenantClient();
        try {
            $shipment = $client->shipment->create($data);
            // dd("DEBUG getRates (Purolator) :", $shipment);
            if (!empty($shipment->messages)) {
                $this->logger->warning('Messages transporteur (getRates)', [
                    'messages' => json_decode(json_encode($shipment->messages), true)
                ]);
            }
            return $shipment->rates;
        } catch (\EasyPost\Exception\Api\BaseException $e) {
            error_log("EasyPost API Error (getRates): " . $e->getMessage());
            throw new RuntimeException("Erreur lors de la récupération des tarifs EasyPost: " . $e->getMessage(), 0, $e);
        }
    }

    public function createShipmentAndBuy(array $data, string $carrierAccountId, string $service): array
    {
        $client = $this->getTenantClient();

        try {
            $shipment = $client->shipment->create($data);
            // if (empty($shipment->rates)) {
            //     dd(
            //         "=== ERREUR PUROLATOR (Pas de tarifs) ===",
            //         "ID du compte demandé : " . $carrierAccountId,
            //         "MESSAGES D'ERREUR DU TRANSPORTEUR :",
            //         $shipment->messages, // <--- C'est ici que la vérité se trouve
            //         "Données envoyées :", 
            //         $data
            //     );
            // }
            
            $rateToBuy = null;
            
            $targetAccountId = trim($carrierAccountId);
            $targetService = trim($service);

            foreach ($shipment->rates as $r) {
                if ($r->carrier_account_id === $targetAccountId && $r->service === $targetService && isset($r->id)) {
                    $rateToBuy = $r;
                    break;
                }
            }
            
            if (!$rateToBuy) {

                $availableServices = array_map(fn($r) => $r->service . ' (' . $r->carrier_account_id . ')', $shipment->rates);
                error_log("Tarif introuvable. Cherché: [$targetService][$targetAccountId]. Trouvés: " . implode(', ', $availableServices));

                $rateToBuy = $shipment->lowestRate();
                if (!isset($rateToBuy->id)) {
                     throw new RuntimeException("Impossible de trouver un tarif achetable pour cette expédition.");
                }
            }

            $bought = $client->shipment->buy($shipment->id, ['rate' => ['id' => $rateToBuy->id]]);

            return [
                'label_url'     => $bought->postage_label->label_url,
                'tracking_code' => $bought->tracking_code,
                'price'         => $rateToBuy->rate,
                'currency'      => $rateToBuy->currency
            ];

        } catch (\EasyPost\Exception\Api\BaseException $e) {
             error_log("EasyPost API Error (buyShipment): " . $e->getMessage());
             throw new RuntimeException("Erreur lors de l'achat de l'étiquette EasyPost: " . $e->getMessage(), 0, $e);
        }
    }

    public function track(string $code): array
    {
        $client = $this->getTenantClient();
        try {
            $tracker = $client->tracker->create(['tracking_code' => $code]);
            return $tracker->status_history ?? [];
        } catch (\EasyPost\Exception\Api\BaseException $e) {
             error_log("EasyPost API Error (track): " . $e->getMessage());
             throw new RuntimeException("Erreur lors du suivi EasyPost: " . $e->getMessage(), 0, $e);
        }
    }
}
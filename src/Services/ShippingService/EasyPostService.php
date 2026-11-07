<?php
// src/Services/ShippingService/EasyPostService.php

namespace App\Services\ShippingService;

use App\Entity\EasyPostConfiguration;
use App\Services\BoutiqueSettingsService\BoutiqueSettingsService;
use App\Services\TenantEntityManagerProvider;
use EasyPost\EasyPostClient;
use LogicException;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Passerelle EasyPost (tarifs, achat d'étiquettes, suivi). Clé utilisée selon le mode d'expédition du site (lot 2 Livraison, 07/11/2026) :
 * `own_key` → clé du site pour l'environnement choisi (test ou réel, réglage `shipping.easypost_environment`) ; `platform` → clé EasyPost
 * de la plateforme (variables EASYPOST_SECRET_KEY et EASYPOST_PLATFORM_ENVIRONMENT), les étiquettes étant payées sur le solde prépayé du
 * site ; `none` → clé du site si elle existe (adresses, essais), sinon aucune. Jamais de repli silencieux sur un autre tarif à l'achat ;
 * étiquettes en PDF ; envoi international avec déclaration douanière fournie par l'appelant (`customs_info`).
 */
class EasyPostService implements ShippingCarrierGatewayInterface
{
    private TenantEntityManagerProvider $emProvider;
    private string $appEnv;
    private LoggerInterface $logger;

    public function __construct(
        TenantEntityManagerProvider $emProvider,
        string $appEnv,
        LoggerInterface $logger,
        private readonly ?BoutiqueSettingsService $settings = null,
        private readonly string $platformApiKey = '',
        private readonly string $platformEnvironment = 'test',
    ) {
        $this->emProvider = $emProvider;
        $this->appEnv = $appEnv;
        $this->logger = $logger;
    }

    /** Mode d'expédition du site : none | own_key | platform */
    public function mode(): string
    {
        return $this->settings?->shippingSettings()['mode'] ?? 'none';
    }

    /** `test` ou `live` : réglage du site (clé propre), environnement de la plateforme (mode plateforme), sinon l'environnement Symfony */
    public function environment(): string
    {
        if ($this->mode() === 'platform') {
            return $this->platformEnvironment === 'live' ? 'live' : 'test';
        }
        $setting = $this->settings?->shippingSettings()['easypostEnvironment'] ?? null;
        if ($setting !== null) {
            return $setting;
        }

        return $this->appEnv === 'prod' ? 'live' : 'test';
    }

    /** Clé de la plateforme renseignée (mode plateforme possible) */
    public function platformConfigured(): bool
    {
        return trim($this->platformApiKey) !== '';
    }

    protected function getTenantClient(): EasyPostClient
    {
        if ($this->mode() === 'platform') {
            if (!$this->platformConfigured()) {
                throw new LogicException('Clé EasyPost de la plateforme absente : étiquettes par la plateforme indisponibles.');
            }

            return new EasyPostClient(trim($this->platformApiKey));
        }
        $em = $this->emProvider->getEntityManager();
        $config = $em->getRepository(EasyPostConfiguration::class)->findOneBy([]);
        if (!$config) {
            throw new LogicException('Aucune configuration EasyPost trouvée pour ce tenant.');
        }
        if ($this->environment() === 'live') {
            try {
                $apiKey = $config->getEasypostApiKeyProd();
            } catch (RuntimeException $e) {
                throw new LogicException('Erreur lors du déchiffrement de la clé API EasyPost Production: ' . $e->getMessage(), 0, $e);
            }
            if (!$apiKey) {
                throw new LogicException('Clé API EasyPost de PRODUCTION manquante ou invalide pour ce tenant.');
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

    /**
     * Le site a-t-il une clé EasyPost pour l'environnement courant (ou la plateforme la sienne en mode plateforme) ? Sans lever
     * d'exception : les adresses d'un site sans EasyPost se gèrent quand même.
     */
    public function hasConfiguration(): bool
    {
        if ($this->mode() === 'platform') {
            return $this->platformConfigured();
        }
        $config = $this->emProvider->getEntityManager()->getRepository(EasyPostConfiguration::class)->findOneBy([]);
        if (!$config) {
            return false;
        }
        try {
            $key = $this->environment() === 'live' ? $config->getEasypostApiKeyProd() : $config->getEasypostApiKeySandbox();
        } catch (RuntimeException) {
            return false;
        }

        return (string) $key !== '';
    }

    public function getRates(array $data): array
    {
        $client = $this->getTenantClient();
        try {
            $shipment = $client->shipment->create($data);
            if (!empty($shipment->messages)) {
                $this->logger->warning('[EasyPost] messages transporteur (tarifs)', ['messages' => json_decode(json_encode($shipment->messages), true)]);
            }

            return is_array($shipment->rates ?? null) ? $shipment->rates : [];
        } catch (\EasyPost\Exception\Api\ApiException $e) {
            $this->logger->error('[EasyPost] tarifs : ' . $e->getMessage());
            throw new RuntimeException('Erreur lors de la récupération des tarifs EasyPost: ' . $e->getMessage(), 0, $e);
        }
    }

    public function createShipmentAndBuy(array $data, string $carrierAccountId, string $service): array
    {
        $client = $this->getTenantClient();
        $data['options'] = ($data['options'] ?? []) + ['label_format' => 'PDF'];
        try {
            $shipment = $client->shipment->create($data);
            $targetAccountId = trim($carrierAccountId);
            $targetService = trim($service);
            $rateToBuy = null;
            $available = [];
            foreach (is_array($shipment->rates ?? null) ? $shipment->rates : [] as $r) {
                $available[] = ($r->service ?? '?') . ' (' . ($r->carrier_account_id ?? '?') . ')';
                if (($r->carrier_account_id ?? null) === $targetAccountId && ($r->service ?? null) === $targetService && isset($r->id)) {
                    $rateToBuy = $r;
                    break;
                }
            }
            if ($rateToBuy === null) {
                // Jamais de repli sur le moins cher : le marchand (ou le client) a choisi un service, un autre serait facturé à sa place
                $this->logger->warning('[EasyPost] tarif demandé introuvable', ['service' => $targetService, 'account' => $targetAccountId, 'available' => $available, 'messages' => json_decode(json_encode($shipment->messages ?? []), true)]);
                throw new RateUnavailableException($targetService, $targetAccountId, $available);
            }
            $bought = $client->shipment->buy($shipment->id, ['rate' => ['id' => $rateToBuy->id]]);

            return [
                'label_url' => (string) ($bought->postage_label->label_url ?? ''),
                'tracking_code' => (string) ($bought->tracking_code ?? ''),
                'price' => (string) $rateToBuy->rate,
                'currency' => (string) $rateToBuy->currency,
                'carrier' => (string) ($rateToBuy->carrier ?? ''),
                'service' => (string) ($rateToBuy->service ?? ''),
            ];
        } catch (\EasyPost\Exception\Api\ApiException $e) {
            $this->logger->error('[EasyPost] achat d\'étiquette : ' . $e->getMessage());
            throw new RuntimeException('Erreur lors de l\'achat de l\'étiquette EasyPost: ' . $e->getMessage(), 0, $e);
        }
    }

    public function track(string $trackingCode, ?string $carrier = null): array
    {
        $client = $this->getTenantClient();
        try {
            $tracker = $client->tracker->create(array_filter(['tracking_code' => $trackingCode, 'carrier' => $carrier]));
            $history = json_decode(json_encode($tracker->tracking_details ?? $tracker->status_history ?? []), true);

            return ['status' => isset($tracker->status) ? (string) $tracker->status : null, 'history' => is_array($history) ? $history : []];
        } catch (\EasyPost\Exception\Api\ApiException $e) {
            $this->logger->error('[EasyPost] suivi : ' . $e->getMessage());
            throw new RuntimeException('Erreur lors du suivi EasyPost: ' . $e->getMessage(), 0, $e);
        }
    }
}

<?php

namespace App\Services\ShippingService;

/**
 * Passerelle vers le fournisseur d'étiquettes (EasyPost en production, simulée dans les tests ; livraison, lot 2, 07/11/2026).
 * Les charges utiles sont celles de l'API EasyPost : `to_address`, `from_address`, `parcel` (onces et pouces), `carrier_accounts`,
 * `customs_info` pour un envoi international, `options`.
 */
interface ShippingCarrierGatewayInterface
{
    /** @return list<object> tarifs d'un envoi (objets `rate` : carrier, service, rate, currency, carrier_account_id, delivery_days) */
    public function getRates(array $data): array;

    /**
     * Crée l'envoi et achète le tarif exact (compte transporteur + service) ; refuse si ce tarif n'est pas proposé (jamais de repli
     * silencieux sur un autre service).
     *
     * @return array{label_url: string, tracking_code: string, price: string, currency: string, carrier: string, service: string}
     * @throws RateUnavailableException|\RuntimeException
     */
    public function createShipmentAndBuy(array $data, string $carrierAccountId, string $service): array;

    /** @return array{status: ?string, history: list<array<string, mixed>>} dernier statut EasyPost (pre_transit, in_transit, out_for_delivery, delivered, return_to_sender, failure, unknown) et historique */
    public function track(string $trackingCode, ?string $carrier = null): array;

    /** Clé disponible pour l'environnement courant (site ou plateforme selon le mode du site) */
    public function hasConfiguration(): bool;

    /** `test` ou `live` */
    public function environment(): string;
}

<?php

namespace App\Services\OrderService;

use App\Entity\Entreprise;
use App\Entity\Order;
use App\Services\TenantEntityManagerProvider;

/**
 * Adresse qui sert au calcul des taxes d'une commande (15/10/2026) : l'adresse de livraison de la commande, sinon
 * (vente au comptoir sans adresse, route caisse /api/order/create-multi-payment) l'adresse de la fiche entreprise du
 * site, c'est-à-dire le lieu de la vente. Sans l'une ni l'autre : null, et TaxEngine ne calcule aucune taxe (statut
 * address_required), comme avant. Les commandes web ont toujours une adresse : leur calcul ne change pas.
 */
class OrderTaxAddressResolver
{
    public function __construct(private readonly TenantEntityManagerProvider $emProvider)
    {
    }

    /** @return array{country: ?string, province: ?string, city: ?string, postalCode: ?string}|null adresse brute, à normaliser par TaxEngine::normalizeAddress */
    public function forOrder(Order $order): ?array
    {
        $address = $order->getShippingAdress();
        if ($address) {
            return ['country' => $address->getCountry(), 'province' => $address->getProvince(), 'city' => $address->getCity(), 'postalCode' => $address->getCodepostal()];
        }
        $site = $this->emProvider->getEntityManager()->getRepository(Entreprise::class)->findOneBy([])?->getAddressEntreprise();
        if (!$site) {
            return null;
        }

        return ['country' => $site->getCountry(), 'province' => $site->getState(), 'city' => $site->getCity(), 'postalCode' => $site->getZip()];
    }
}

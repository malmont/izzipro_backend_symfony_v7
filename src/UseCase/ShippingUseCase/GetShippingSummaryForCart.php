<?php

namespace App\UseCase\ShippingUseCase;

use App\Dto\CartItemDto;
use App\Dto\RateSummaryDto;
use App\Entity\ProductShipping;
use App\Services\ShippingService\ShippingService;
use App\Services\ShippingService\ShipmentAddressBuilder;
use App\Services\TenantEntityManagerProvider;
use LogicException;

class GetShippingSummaryForCart
{
    public function __construct(
        private TenantEntityManagerProvider $emProvider,
        private ShippingService           $shippingService,
        private ShipmentAddressBuilder    $builder,
        private readonly ?\App\Services\ShippingService\ProductShippingResolver $resolver = null,
        private readonly ?\App\Services\ShippingService\ShippingRateStore $rates = null, // lot CHECKOUT-LIVRAISON
    ) {}

    /**
     * @param CartItemDto[] $cartItems
     * @param array         $toAddress    
     * @param array         $fromAddress  
     * @param string[]      $carrierAccountIds
     * @return RateSummaryDto[]
     */
    public function execute(
        array  $cartItems,
        array  $toAddress,
        array  $fromAddress,
        array  $carrierAccountIds
    ): array {
        $em = $this->emProvider->getEntityManager();
        $shippingRepo = $em->getRepository(ProductShipping::class);

        // 1) Charger et dupliquer les configs ProductShipping
        $items = [];
        foreach ($cartItems as $ci) {
            // Fiche poids du produit, sinon valeurs de sa classe, sinon poids par défaut du site (livraison, lot 1 bis, 07/11/2026)
            $ps = $this->resolver->forProductId((int) $ci->productId);
            for ($i = 0; $i < $ci->quantity; $i++) {
                $items[] = $ps;
            }
        }

        // 2) Adapter les adresses au format EasyPost
        $to   = $this->builder->buildTo($toAddress);
        $from = $this->builder->buildFrom();

        $rateOptions = $this->shippingService->getRates($items, $to, $from, $carrierAccountIds);

        // 5) Agréger par carrier+service
        $buckets = [];
        foreach ($rateOptions as $r) {
            $key = $r->carrier . '||' . $r->service . '||' . $r->carrierAccountId; // un compte par regroupement (deux comptes ne s'additionnent pas)
            if (! isset($buckets[$key])) {
                $buckets[$key] = [
                    'carrier'       => $r->carrier,
                    'service'       => $r->service,
                    'totalPrice'    => 0.0,
                    'currency'      => $r->currency,
                    'estimatedDays' => 0,
                    'parcelCount'   => 0,
                    'carrierAccountId' => $r->carrierAccountId,
                ];
            }
            // ⚠️ on utilise $r->price (pas ->rate)
            $buckets[$key]['totalPrice']    += $r->price;
            $buckets[$key]['estimatedDays']  = max(
                $buckets[$key]['estimatedDays'],
                $r->estimatedDays
            );
            $buckets[$key]['parcelCount']++;
        }

        // 6) Construire les DTO de résumé (attention à l’ordre des arguments)
        // lot CHECKOUT-LIVRAISON : chaque tarif reçoit son jeton, gardé par le serveur avec ce qu'il couvre (articles et quantités,
        // destination, compte et service, montant) ; le devis web ne croira que lui
        $products = [];
        foreach ($cartItems as $ci) {
            $products[(int) $ci->productId] = ($products[(int) $ci->productId] ?? 0) + (int) $ci->quantity;
        }
        $parcels = $this->shippingService->countParcels($items);
        $summaries = [];
        foreach ($buckets as $b) {
            $summary = new RateSummaryDto(
                $b['carrier'],
                $b['service'],
                (int) round($b['totalPrice'] * 100), // EasyPost rend des dollars : cents comme le reste de la boutique
                $b['currency'],
                $b['parcelCount'],
                $b['estimatedDays']
            );
            // un jeton seulement pour un tarif de TOUS les colis, d'un seul compte, vers une destination complète (pays et code postal)
            $destination = \App\Services\ShippingService\ShippingRateStore::destination($toAddress);
            if ($this->rates !== null && is_string($b['carrierAccountId']) && $b['carrierAccountId'] !== '' && $b['parcelCount'] === $parcels
                && $destination['country'] !== null && $destination['postalCode'] !== null) {
                $summary->rateToken = $this->rates->put($products, $toAddress, $b['carrierAccountId'], $b['service'], $summary->totalPrice, $b['currency']);
            }
            $summaries[] = $summary;
        }

        return $summaries;
    }
}


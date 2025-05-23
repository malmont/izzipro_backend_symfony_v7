<?php

namespace App\UseCase\ShippingUseCase;

use App\Dto\CartItemDto;
use App\Repository\ProductShippingRepository;
use App\Repository\PackagingTypeRepository;
use App\Services\ShippingService\ShippingService;
use LogicException;
use App\Services\ShippingService\ShipmentAddressBuilder;

class GetShippingRatesForCart
{
    public function __construct(
        private ProductShippingRepository $shippingRepo,
        private PackagingTypeRepository   $templateRepo,
        private ShippingService           $shippingService,
        private ShipmentAddressBuilder    $builder
    ) {}

    /**
     * @param CartItemDto[] $cartItems
     * @param array         $toAddress      // attend keys : street1, street2, city, province, postal_code, country
     * @param array         $fromAddress    // mêmes clés que $toAddress
     * @param string[]      $carrierAccountIds
     * @return array        tableau de RateOptionDto
     */
    public function execute(
        array $cartItems,
        array $toAddress,
        array $fromAddress,
        array $carrierAccountIds
    ): array {
        // 1. Récupérer et valider ProductShipping pour chaque item
        $items = [];
        foreach ($cartItems as $ci) {
            $ps = $this->shippingRepo->findOneBy(['product' => $ci->productId]);
            if (! $ps) {
                throw new \LogicException(
                    "Aucune configuration d'expédition trouvée pour le produit ID {$ci->productId}. "
                  . "Merci de créer une ProductShipping pour ce produit."
                );
            }
            for ($i = 0; $i < $ci->quantity; $i++) {
                $items[] = $ps;
            }
        }

        // 2. Adapter les adresses au format EasyPost
        $to   = $this->builder->buildTo($toAddress);
        $from = $this->builder->buildFrom();

        // 3. Charger tous les templates d’emballage et calculer les colis
        $templates = $this->templateRepo->findAll();
        $parcels   = $this->shippingService->getParcelsFromItems($items, $templates);

        // 4. Récupérer les tarifs EasyPost
        return $this->shippingService->getRates(
            $parcels,
            $to,
            $from,
            $carrierAccountIds
        );
    }
}

<?php

namespace App\UseCase\ShippingUseCase;

use App\Dto\CartItemDto;
use App\Repository\ProductShippingRepository;
use App\Repository\PackagingTypeRepository;
use App\Services\ShippingService\ShippingService;
use LogicException;

class GetShippingRatesForCart
{
    public function __construct(
        private ProductShippingRepository $shippingRepo,
        private PackagingTypeRepository   $templateRepo,
        private ShippingService           $shippingService
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
        $to = [
            'street1' => $toAddress['street1'],
            'street2' => $toAddress['street2'] ?? null,
            'city'    => $toAddress['city'],
            'state'   => $toAddress['province'],
            'zip'     => $toAddress['postal_code'],
            'country' => $toAddress['country'],
        ];
        $from = [
            'street1' => $fromAddress['street1'],
            'street2' => $fromAddress['street2'] ?? null,
            'city'    => $fromAddress['city'],
            'state'   => $fromAddress['province'],
            'zip'     => $fromAddress['postal_code'],
            'country' => $fromAddress['country'],
        ];

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

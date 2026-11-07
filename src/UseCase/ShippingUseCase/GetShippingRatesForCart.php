<?php
namespace App\UseCase\ShippingUseCase;

use App\Dto\CartItemDto;
use App\Dto\ParcelSummaryDto;
use App\Entity\ProductShipping;
use App\Services\ShippingService\ShippingService;
use App\Services\ShippingService\ShipmentAddressBuilder;
use App\Services\TenantEntityManagerProvider;
use LogicException;


class GetShippingRatesForCart
{
    // MODIFICATION 1 : Le constructeur est refactorisé
    public function __construct(
        private TenantEntityManagerProvider $emProvider,
        private ShippingService           $shippingService,
        private ShipmentAddressBuilder    $builder,
        private readonly ?\App\Services\ShippingService\ProductShippingResolver $resolver = null
    ) {}

    /**
     * @param CartItemDto[] $cartItems
     * @param array         $toAddress
     * @param array         $fromAddress
     * @param string[]      $carrierAccountIds
     * @return array
     */
    public function execute(
        array $cartItems,
        array $toAddress,
        array $fromAddress,
        array $carrierAccountIds
    ): array {
        // MODIFICATION 2 : On récupère l'EM et les repositories ici
        $em = $this->emProvider->getEntityManager();
        $shippingRepo = $em->getRepository(ProductShipping::class);

        // 1. Récupérer et valider ProductShipping pour chaque item
        $items = [];
        foreach ($cartItems as $ci) {
            // Fiche poids du produit, sinon valeurs de sa classe, sinon poids par défaut du site (livraison, lot 1 bis, 07/11/2026)
            $ps = $this->resolver->forProductId((int) $ci->productId);
            for ($i = 0; $i < $ci->quantity; $i++) {
                $items[] = $ps;
            }
        }

        // 2. Adapter les adresses au format EasyPost
        $to   = $this->builder->buildTo($toAddress);
        $from = $this->builder->buildFrom();

        return $this->shippingService->getRates(
            $items,
            $to,
            $from,
            $carrierAccountIds
        );
    }
}

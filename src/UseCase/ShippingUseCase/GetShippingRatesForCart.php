<?php
namespace App\UseCase\ShippingUseCase;

use App\Dto\CartItemDto;
use App\Dto\ParcelSummaryDto;
use App\Entity\ProductShipping; // <-- On importe les entités
use App\Entity\PackagingType;
use App\Services\ShippingService\ShippingService;
use App\Services\ShippingService\ShipmentAddressBuilder;
use App\Services\TenantEntityManagerProvider; // <-- On importe notre provider
use LogicException;


class GetShippingRatesForCart
{
    // MODIFICATION 1 : Le constructeur est refactorisé
    public function __construct(
        private TenantEntityManagerProvider $emProvider,
        private ShippingService           $shippingService,
        private ShipmentAddressBuilder    $builder
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
        $templateRepo = $em->getRepository(PackagingType::class);

        // 1. Récupérer et valider ProductShipping pour chaque item
        $items = [];
        foreach ($cartItems as $ci) {
            // On utilise le repository obtenu depuis l'EM du tenant
            $ps = $shippingRepo->findOneBy(['product' => $ci->productId]);
            if (! $ps) {
                throw new LogicException(
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

        // 3. Charger tous les templates d’emballage
        $templates = $templateRepo->findAll();
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
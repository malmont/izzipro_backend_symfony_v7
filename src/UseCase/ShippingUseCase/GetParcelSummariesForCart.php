<?php
namespace App\UseCase\ShippingUseCase;

use App\Dto\CartItemDto;
use App\Dto\ParcelSummaryDto;
use App\Entity\ProductShipping;
use App\Services\ShippingService\ShippingService;
use App\Services\TenantEntityManagerProvider;
use LogicException;

class GetParcelSummariesForCart
{
    // MODIFICATION 1 : Le constructeur est refactorisé
    public function __construct(
        private TenantEntityManagerProvider $emProvider,
        private ShippingService           $shippingService,
        private readonly ?\App\Services\ShippingService\ProductShippingResolver $resolver = null
    ) {}

    /**
     * @param CartItemDto[] $cartItems
     * @return ParcelSummaryDto[]
     */
    public function execute(
        array $cartItems
 
    ): array {
        // MODIFICATION 2 : On récupère l'EM et le repository ici
        $em = $this->emProvider->getEntityManager();
        $shippingRepo = $em->getRepository(ProductShipping::class);

        $items = [];
        foreach ($cartItems as $ci) {
            // Fiche poids du produit, sinon valeurs de sa classe, sinon poids par défaut du site (livraison, lot 1 bis, 07/11/2026)
            $ps = $this->resolver->forProductId((int) $ci->productId);

            for ($i = 0; $i < $ci->quantity; $i++) {
                $items[] = $ps;
            }
        }
         $raw = $this->shippingService->getParcelSummaries(
            $items
        );

        return array_map(
            fn(array $r) => new ParcelSummaryDto(
                $r['index'],
                $r['weight'],
                $r['length'],
                $r['width'],
                $r['height']
            ),
            $raw
        );
    }
}


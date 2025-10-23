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
        private ShippingService           $shippingService
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


<?php
namespace App\UseCase\ShippingUseCase;

use App\Dto\CartItemDto;
use App\Dto\ParcelSummaryDto;
use App\Entity\ProductShipping; // <-- On importe l'entité
use App\Services\ShippingService\ShippingService;
use App\Services\TenantEntityManagerProvider; // <-- On importe notre provider

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
        array $cartItems,
        array $to,
        array $from,
        array $carrierAccountIds
    ): array {
        // MODIFICATION 2 : On récupère l'EM et le repository ici
        $em = $this->emProvider->getEntityManager();
        $shippingRepo = $em->getRepository(ProductShipping::class);

        $items = [];
        foreach ($cartItems as $ci) {
            // On utilise le repository obtenu depuis l'EM du tenant
            $ps = $shippingRepo->findOneBy(['product' => $ci->productId]);
            for ($i = 0; $i < $ci->quantity; $i++) {
                $items[] = $ps;
            }
        }

        // Le reste de votre logique est inchangée
        $raw = $this->shippingService->getParcelSummaries(
            $items,
            $to,
            $from,
            $carrierAccountIds
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
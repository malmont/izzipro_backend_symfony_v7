<?php
namespace App\UseCase\ShippingUseCase;

use App\Dto\CartItemDto;
use App\Dto\ParcelSummaryDto;
use App\Repository\ProductShippingRepository;
use App\Services\ShippingService\ShippingService;

class GetParcelSummariesForCart
{
    public function __construct(
        private ProductShippingRepository $shippingRepo,
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
        $items = [];
        foreach ($cartItems as $ci) {
            $ps = $this->shippingRepo->findOneBy(['product' => $ci->productId]);
            for ($i = 0; $i < $ci->quantity; $i++) {
                $items[] = $ps;
            }
        }

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

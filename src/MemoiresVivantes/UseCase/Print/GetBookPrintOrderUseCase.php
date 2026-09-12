<?php

namespace App\MemoiresVivantes\UseCase\Print;

use App\MemoiresVivantes\Entity\BookPrintOrder;
use App\MemoiresVivantes\Services\LuluPrintService;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class GetBookPrintOrderUseCase
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly LuluPrintService $luluPrintService
    ) {}

    /**
     * Récupère une commande et actualise son statut en direct auprès de Lulu si nécessaire.
     */
    public function execute(string $orderId): BookPrintOrder
    {
        $em = $this->emProvider->getEntityManager();
        /** @var BookPrintOrder|null $order */
        $order = $em->getRepository(BookPrintOrder::class)->find($orderId);

        if (!$order) {
            throw new NotFoundHttpException('Commande d\'impression introuvable.');
        }

        // Si la commande est en cours de production ou tout juste créée, interroger Lulu pour obtenir la mise à jour
        if ($order->getLuluPrintJobId() && in_array($order->getStatus(), ['created', 'in_production'])) {
            $this->luluPrintService->syncOrderStatus($order);
        }

        return $order;
    }
}

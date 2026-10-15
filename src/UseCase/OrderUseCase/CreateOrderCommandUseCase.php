<?php

namespace App\UseCase\OrderUseCase;

use App\Entity\Order;
use App\Entity\User;
use App\Dto\ICreateOrderDTO;
use App\Services\EntityRetrieverService;
use App\Services\OrderService\OrderCreationService;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

use App\Entity\OrderSource;
use App\Entity\Adress;
use App\Entity\Carrier;
use App\Entity\StatusCommande;
use App\Entity\OrderType;


class CreateOrderCommandUseCase
{
    private TenantEntityManagerProvider $emProvider;
    private EntityRetrieverService $entityRetrieverService;
    private OrderCreationService $orderCreationService;

    public function __construct(
        TenantEntityManagerProvider $emProvider,
        EntityRetrieverService $entityRetrieverService,
        OrderCreationService $orderCreationService
    ) {
        $this->emProvider = $emProvider;
        $this->entityRetrieverService = $entityRetrieverService;
        $this->orderCreationService = $orderCreationService;
    }

    public function execute(ICreateOrderDTO $orderDTO, User $user)
    {
        try {
            $orderSource = $this->entityRetrieverService->findOrFail(OrderSource::class, $orderDTO->getOrderSource(), 'Invalid order source ID');
            // Adresse facultative pour la seule route caisse (CreateOrderMultiPaymentDTO, 15/10/2026) ; CreateOrderDTO
            // (web) en fournit toujours une et garde ce contrôle
            $address = $orderDTO->getAddressId() !== null
                ? $this->entityRetrieverService->findOrFail(Adress::class, $orderDTO->getAddressId(), 'Invalid address ID')
                : null;
            $carrierId = $orderDTO->getCarrierId();
            $carrier = null;

            if ($carrierId) {
                $carrier = $this->entityRetrieverService->findOrFail(Carrier::class, $carrierId, 'Invalid carrier ID');
            }
            $statusCommande = $this->entityRetrieverService->findOrFail(StatusCommande::class, 3, 'Invalid status ID');
            $orderType = $this->entityRetrieverService->findOrFail(OrderType::class, $orderDTO->getTypeOrder(), 'Invalid order type ID');
        } catch (NotFoundHttpException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 404);
        }
        $order = $this->orderCreationService->createOrder(
            $user,
            $orderSource,
            $address,
            $carrier,
            $statusCommande,
            $orderType
        );

        if (method_exists($orderDTO, 'getGuestLicenseNumber') && $orderDTO->getGuestLicenseNumber()) {
            $order->setGuestLicenseNumber($orderDTO->getGuestLicenseNumber());
        }
        if (method_exists($orderDTO, 'getGuestLicenseExpirationDate') && $orderDTO->getGuestLicenseExpirationDate()) {
            $order->setGuestLicenseExpirationDate(new \DateTime($orderDTO->getGuestLicenseExpirationDate()));
        }

        $em = $this->emProvider->getEntityManager();
        $em->persist($order);
        return $order;
    }
}

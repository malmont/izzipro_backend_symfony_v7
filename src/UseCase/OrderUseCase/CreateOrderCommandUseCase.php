<?php
namespace App\UseCase\OrderUseCase;

use App\Entity\Order;
use App\Entity\User;
use App\DTO\ICreateOrderDTO;
use App\Services\EntityRetrieverService;
use App\Services\OrderService\OrderCreationService;
use App\Services\TenantEntityManagerProvider; // <-- On importe notre provider
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

// Les entités sont toujours nécessaires pour les vérifications de type si besoin
use App\Entity\OrderSource;
use App\Entity\Adress;
use App\Entity\Carrier;
use App\Entity\StatusCommande;
use App\Entity\OrderType;


class CreateOrderCommandUseCase
{
    // MODIFICATION 1 : La propriété $em est remplacée par $emProvider
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
        // Les appels à votre service restent INCHANGÉS, car il est déjà "tenant-aware"
        try {
            $orderSource = $this->entityRetrieverService->findOrFail(OrderSource::class, $orderDTO->getOrderSource(), 'Invalid order source ID');
            $address = $this->entityRetrieverService->findOrFail(Adress::class, $orderDTO->getAddressId(), 'Invalid address ID');
            $carrier = $this->entityRetrieverService->findOrFail(Carrier::class, $orderDTO->getCarrierId(), 'Invalid carrier ID');
            $statusCommande = $this->entityRetrieverService->findOrFail(StatusCommande::class, 3, 'Invalid status ID');
            $orderType = $this->entityRetrieverService->findOrFail(OrderType::class, $orderDTO->getTypeOrder(), 'Invalid order type ID');
        } catch (NotFoundHttpException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 404);
        }

        // Cet appel reste INCHANGÉ
        $order = $this->orderCreationService->createOrder(
            $user,
            $orderSource,
            $address,
            $carrier,
            $statusCommande,
            $orderType
        );

        // MODIFICATION 2 : On utilise le provider pour obtenir l'EM du tenant et persister
        $em = $this->emProvider->getEntityManager();
        $em->persist($order);
        return $order;
    }
}
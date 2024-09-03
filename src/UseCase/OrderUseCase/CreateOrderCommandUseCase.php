<?php
namespace App\UseCase\OrderUseCase;

use App\Entity\Order;
use App\Entity\User;
use App\Entity\OrderSource;
use App\Entity\Carrier;
use App\Entity\StatusCommande;
use App\Entity\OrderType;
use App\Entity\Adress;
use App\DTO\CreateOrderDTO;
use App\Services\EntityRetrieverService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

class CreateOrderCommandUseCase
{
    private $em;
    private $entityRetrieverService;

    // L'injection automatique se fait via le type hinting dans le constructeur
    public function __construct(EntityManagerInterface $em, EntityRetrieverService $entityRetrieverService)
    {
        $this->em = $em;
        $this->entityRetrieverService = $entityRetrieverService;
    }

    public function execute(CreateOrderDTO $orderDTO, User $user)
    {
        try {
            $orderSource = $this->entityRetrieverService->findOrFail(OrderSource::class, $orderDTO->getOrderSource(), 'Invalid order source ID');
            $address = $this->entityRetrieverService->findOrFail(Adress::class, $orderDTO->getAddressId(), 'Invalid address ID');
            $carrier = $this->entityRetrieverService->findOrFail(Carrier::class, $orderDTO->getCarrierId(), 'Invalid carrier ID');
            $statusCommande = $this->entityRetrieverService->findOrFail(StatusCommande::class, 3, 'Invalid status ID');
            $orderType = $this->entityRetrieverService->findOrFail(OrderType::class, $orderDTO->getTypeOrder(), 'Invalid order type ID');
        } catch (NotFoundHttpException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }

        $order = new Order();
        $order->setReference('REF#' . uniqid());
        $order->setUserId($user);
        $order->setOrderType($orderType);
        $order->setOrderSource($orderSource);
        $order->setOrderDate(new \DateTime());
        $order->setShippingAdress($address);
        $order->setCarrier($carrier);
        $order->setStatus($statusCommande);

        // Persist the order entity
        $this->em->persist($order);

        return $order;
    }
}

<?php
namespace App\UseCase\OrderUseCase;

use App\Entity\Order;
use App\Entity\User;
use App\Entity\Adress;
use App\Entity\Carrier;
use App\Entity\StatusCommande;
use App\Entity\OrderType;
use App\DTO\CreateOrderDTO;
use App\Repository\OrderSourceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

class CreateOrderCommandUseCase
{
    private $em;
    private $orderSourceRepository;

    public function __construct(EntityManagerInterface $em, OrderSourceRepository $orderSourceRepository)
    {
        $this->em = $em;
        $this->orderSourceRepository = $orderSourceRepository;
    }

    public function execute(CreateOrderDTO $orderDTO, User $user)
    {
        $orderSource = $this->orderSourceRepository->find($orderDTO->getOrderSource());
        if (!$orderSource) {
            return new JsonResponse(['error' => 'Invalid order source ID'], 400);
        }
        
        $address = $this->em->getRepository(Adress::class)->find($orderDTO->getAddressId());
        if (!$address) {
            return new JsonResponse(['error' => 'Invalid address ID'], 400);
        }

        $carrier = $this->em->getRepository(Carrier::class)->find($orderDTO->getCarrierId());
        if (!$carrier) {
            return new JsonResponse(['error' => 'Invalid carrier ID'], 400);
        }

        $statusCommande = $this->em->getRepository(StatusCommande::class)->find(3);
        $orderType = $this->em->getRepository(OrderType::class)->find($orderDTO->getTypeOrder());

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

<?php
namespace App\UseCase\OrderUseCase;

use App\Entity\Order;
use App\Entity\Adress;
use App\Entity\Carrier;
use App\Entity\StatusCommande;
use App\Entity\OrderType;
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

    public function execute(array $data, $user)
    {
        $orderSource = $this->orderSourceRepository->find($data['orderSource']);
        if (!$orderSource) {
            return new JsonResponse(['error' => 'Invalid order source ID'], 400);
        }
        $address = $this->em->getRepository(Adress::class)->find($data['addressId']);
        if (!$address) {
            return new JsonResponse(['error' => 'Invalid address ID'], 400);
        }
        $carrier = $this->em->getRepository(Carrier::class)->find($data['carrierId']);
        if (!$carrier) {
            return new JsonResponse(['error' => 'Invalid carrier ID'], 400);
        }
        $statusCommande = $this->em->getRepository(StatusCommande::class)->find(3);
        $orderType = $this->em->getRepository(OrderType::class)->find(1);
        $order = new Order();
        $order->setReference('REF#' . uniqid());
        $order->setUserId($user);
        $order->setOrderType($orderType);
        $order->setOrderSource($orderSource);
        $order->setOrderDate(new \DateTime());
        $order->setShippingAdress($address);
        $order->setCarrier($carrier);
        $order->setStatus($statusCommande);
        // Additional fields like address, carrier can be set here.

        $this->em->persist($order);
        return $order;
    }
}

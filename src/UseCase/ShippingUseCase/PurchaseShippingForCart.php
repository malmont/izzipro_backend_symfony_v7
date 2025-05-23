<?php
// src/UseCase/ShippingUseCase/PurchaseShippingForCart.php

namespace App\UseCase\ShippingUseCase;

use App\Dto\CartItemDto;
use App\Dto\ShipmentLabelDto;
use App\Entity\ShippingOrder;
use App\Entity\Parcel;
use App\Entity\ShippingLabel;
use App\Entity\Order;
use App\Repository\PackagingTypeRepository;
use App\Repository\ProductShippingRepository;
use App\Services\ShippingService\ShippingService;
use Doctrine\ORM\EntityManagerInterface;
use App\Services\ShippingService\ShipmentAddressBuilder;

class PurchaseShippingForCart
{

    public function __construct(
        private ProductShippingRepository $shippingRepo,
        private ShippingService           $shippingService,
        private EntityManagerInterface    $em,
        private PackagingTypeRepository   $templateRepo,
        private ShipmentAddressBuilder    $builder
    ) {}

    /**
     * @param CartItemDto[] $cartItems
     * @return ShipmentLabelDto[]
     */
    public function execute(
        array  $cartItems,
        array  $toAddress,
        array  $fromAddress,
        string $carrierAccountId,
        string $service,
        int    $orderId,
    ): array {

        $order = $this->em->find(Order::class, $orderId);
        if (! $order) {
            throw new LogicException("Order #{$orderId} introuvable.");
        }
        // 1) Récupérer chaque ProductShipping x quantité
        $items = [];
        foreach ($cartItems as $ci) {
            $ps = $this->shippingRepo->findOneBy(['product' => $ci->productId]);
            if (! $ps) {
                throw new LogicException("Pas de config shipping pour le produit {$ci->productId}");
            }
            for ($i = 0; $i < $ci->quantity; $i++) {
                $items[] = $ps;
            }
        }
        $to   = $this->builder->buildTo($toAddress);
        $from = $this->builder->buildFrom();

        $templates  = $this->templateRepo->findAll();
        $parcelsDto = $this->shippingService->getParcelsFromItems($items, $templates);

        // 2) Appel au service d’achat des étiquettes
        $labelsRaw = $this->shippingService->purchase(
            $items,
            $to,
            $from,
            $carrierAccountId,
            $service
        );

        // 3) Créer et persister la commande de shipping
        $shippingOrder = new ShippingOrder();
        // si vous souhaitez lier à une entité Order existante, appelez $shippingOrder->setOrder(...)
        $shippingOrder
            ->setOdershipping($order)
            ->setCarrierAccountId($carrierAccountId)
            ->setService($service)
            ->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($shippingOrder);

        // 4) Pour chaque label brut, créer Parcel + ShippingLabel
        foreach ($labelsRaw as $i => $raw) {
            $dto = $parcelsDto[$i];

            $parcel = (new Parcel())
                ->setShippingOrder($shippingOrder)
                ->setIndex($i + 1)
                ->setWeight($dto->weight)
                ->setLength($dto->length)
                ->setWidth($dto->width)
                ->setHeight($dto->height)
            ;
            $this->em->persist($parcel);

            $label = (new ShippingLabel())
                ->setParcel($parcel)
                ->setLabelUrl($raw['label_url'])
                ->setTrackingCode($raw['tracking_code'])
                ->setCreatedAt(new \DateTimeImmutable());
            $this->em->persist($label);
        }

        // 5) Exécuter la transaction
        $this->em->flush();

        // 6) Retourner les DTO pour le front
        return array_map(
            fn(array $r) => new ShipmentLabelDto(
                $r['label_url'],
                $r['tracking_code']
            ),
            $labelsRaw
        );
    }
}

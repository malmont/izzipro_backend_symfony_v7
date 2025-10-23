<?php
// src/UseCase/ShippingUseCase/PurchaseShippingForCart.php

namespace App\UseCase\ShippingUseCase;

use App\Dto\CartItemDto;
use App\Dto\ShipmentLabelDto;
use App\Entity\ShippingOrder;
use App\Entity\Parcel;
use App\Entity\ShippingLabel;
use App\Entity\Order;
use App\Entity\ProductShipping;
use App\Services\ShippingService\ShippingService;
use App\Services\ShippingService\ShipmentAddressBuilder;
use App\Services\TenantEntityManagerProvider;
use LogicException;

class PurchaseShippingForCart
{
    public function __construct(
        private TenantEntityManagerProvider $emProvider,
        private ShippingService           $shippingService,
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

        $em = $this->emProvider->getEntityManager();
        $shippingRepo = $em->getRepository(ProductShipping::class);

        $order = $em->find(Order::class, $orderId);
        if (!$order) {
            throw new LogicException("Order #{$orderId} introuvable.");
        }

        $items = [];
        foreach ($cartItems as $ci) {
            $ps = $shippingRepo->findOneBy(['product' => $ci->productId]);
            if (!$ps) {
                throw new LogicException("Pas de config shipping pour le produit {$ci->productId}");
            }
            for ($i = 0; $i < $ci->quantity; $i++) {
                $items[] = $ps;
            }
        }
        $to   = $this->builder->buildTo($toAddress);
        $from = $this->builder->buildFrom();
        $parcelsData = $this->shippingService->getParcelSummaries($items);
        $labelsRaw = $this->shippingService->purchase(
            $items,
            $to,
            $from,
            $carrierAccountId,
            $service
        );
        $shippingOrder = new ShippingOrder();
        $shippingOrder
            ->setOdershipping($order)
            ->setCarrierAccountId($carrierAccountId)
            ->setService($service)
            ->setCreatedAt(new \DateTimeImmutable());
        $em->persist($shippingOrder);

        foreach ($labelsRaw as $i => $raw) {
            $dto = $parcelsData[$i]; 

            $parcel = (new Parcel())
                ->setShippingOrder($shippingOrder)
                ->setIndex($i + 1)
                ->setWeight($dto['weight'])
                ->setLength($dto['length'])
                ->setWidth($dto['width'])
                ->setHeight($dto['height'])
            ;
            $em->persist($parcel);

            $label = (new ShippingLabel())
                ->setParcel($parcel)
                ->setLabelUrl($raw['label_url'])
                ->setTrackingCode($raw['tracking_code'])
                ->setCreatedAt(new \DateTimeImmutable());
            $em->persist($label);
        }

        // 6) Exécuter la transaction sur la BDD du tenant (l'ancienne étape 5)
        $em->flush();

        // 7) Retourner les DTO pour le front (l'ancienne étape 6)
        return array_map(
            fn(array $r) => new ShipmentLabelDto(
                $r['label_url'],
                $r['tracking_code']
            ),
            $labelsRaw
        );
    }
}


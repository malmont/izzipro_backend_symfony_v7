<?php
// src/UseCase/ShippingUseCase/PurchaseShippingForCart.php

namespace App\UseCase\ShippingUseCase;

use App\Dto\CartItemDto;
use App\Dto\ShipmentLabelDto;
use App\Entity\ShippingOrder;
use App\Entity\Parcel;
use App\Entity\ShippingLabel;
use App\Entity\Order;
use App\Entity\PackagingType;
use App\Entity\ProductShipping;
use App\Services\ShippingService\ShippingService;
use App\Services\ShippingService\ShipmentAddressBuilder;
use App\Services\TenantEntityManagerProvider; // <-- On importe notre provider
use LogicException;

class PurchaseShippingForCart
{
    // MODIFICATION 1 : Le constructeur est refactorisé
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

        // MODIFICATION 2 : On récupère l'EM et les repositories ici
        $em = $this->emProvider->getEntityManager();
        $shippingRepo = $em->getRepository(ProductShipping::class);
        $templateRepo = $em->getRepository(PackagingType::class);

        $order = $em->find(Order::class, $orderId);
        if (!$order) {
            throw new LogicException("Order #{$orderId} introuvable.");
        }

        // 1) Récupérer chaque ProductShipping x quantité
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

        $templates  = $templateRepo->findAll();
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
        $shippingOrder
            ->setOdershipping($order)
            ->setCarrierAccountId($carrierAccountId)
            ->setService($service)
            ->setCreatedAt(new \DateTimeImmutable());
        $em->persist($shippingOrder);

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
            $em->persist($parcel);

            $label = (new ShippingLabel())
                ->setParcel($parcel)
                ->setLabelUrl($raw['label_url'])
                ->setTrackingCode($raw['tracking_code'])
                ->setCreatedAt(new \DateTimeImmutable());
            $em->persist($label);
        }

        // 5) Exécuter la transaction sur la BDD du tenant
        $em->flush();

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
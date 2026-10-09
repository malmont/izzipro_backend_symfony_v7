<?php

namespace App\UseCase\OrderUseCase;

use App\Dto\CartQuoteInputDto;
use App\Entity\Order;
use App\Entity\ProductVariant;
use App\Services\EntityRetrieverService;
use App\Services\OrderService\CartQuoteCalculator;
use App\Services\OrderService\CartQuoteException;
use App\Services\OrderService\OrderItemService;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Lignes d'une commande : prix unitaires et frais de livraison pris dans le devis du serveur (CartQuoteCalculator :
 * vente au prix de la variante ou du produit, location au tarif du forfait × durée, règles de réservation), stock des
 * ventes décrémenté. Le navigateur n'impose aucun montant ; les anciens priceShipping ne servent qu'au tarif EasyPost.
 */
class ProcessOrderItemsUseCase
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly EntityRetrieverService $entityRetrieverService,
        private readonly OrderItemService $orderItemService,
        private readonly CartQuoteCalculator $quotes
    ) {
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return float sous-total (articles + livraison, cents) ; négatif pour un retour (typeOrderId ≠ 1)
     * @throws BadRequestHttpException devis refusé (stock, règle de réservation, article inconnu)
     */
    public function execute(Order $order, array $items, UpdateStockAndInventoryUseCase $updateStockAndInventory, int $typeOrderId, ?float $priceShipping, ?int $carrierId = null): float
    {
        $em = $this->emProvider->getEntityManager();
        $isCancel = $typeOrderId !== 1;

        try {
            $address = $order->getShippingAdress();
            $quote = $this->quotes->quote(CartQuoteInputDto::fromArray(['items' => $items, 'carrierId' => $carrierId, 'shippingPrice' => $priceShipping,
                'shippingAddress' => $address ? ['country' => $address->getCountry(), 'province' => $address->getProvince(), 'city' => $address->getCity(), 'postalCode' => $address->getCodepostal()] : null]));
            $order->setPendingTaxCalculationId($quote['taxCalculationId']); // transaction Stripe Tax enregistrée après le paiement
            $order->setPendingTaxes($quote['taxes']); // mêmes taxes que le devis et que le montant autorisé
        } catch (CartQuoteException $e) {
            throw new BadRequestHttpException($e->getMessage(), $e);
        }

        $order->setShippingCost((float) $quote['shipping']);
        $subtotal = (float) $quote['shipping'];

        foreach ($quote['lines'] as $i => $line) {
            $itemData = $items[$i];
            $productVariant = $this->entityRetrieverService->findOrFail(ProductVariant::class, $line['productVariantId'], 'Product variant not found');

            if ($line['kind'] === 'sale') {
                $updateStockAndInventory->execute($productVariant, $line['quantity'], $isCancel);
            }

            $orderItem = $this->orderItemService->createOrderItem($order, $productVariant, $line['quantity'], (float) $line['unitPrice']);

            if (isset($itemData['licenseNumber'])) {
                $orderItem->setLicenseNumber($itemData['licenseNumber']);
            } elseif ($order->getGuestLicenseNumber()) {
                $orderItem->setLicenseNumber($order->getGuestLicenseNumber());
            } elseif ($order->getUserId() && $order->getUserId()->getLicenseNumber()) {
                $orderItem->setLicenseNumber($order->getUserId()->getLicenseNumber());
            }

            if (isset($itemData['licenseExpirationDate'])) {
                $orderItem->setLicenseExpirationDate(new \DateTime($itemData['licenseExpirationDate']));
            } elseif ($order->getGuestLicenseExpirationDate()) {
                $orderItem->setLicenseExpirationDate($order->getGuestLicenseExpirationDate());
            } elseif ($order->getUserId() && $order->getUserId()->getLicenseExpirationDate()) {
                $orderItem->setLicenseExpirationDate($order->getUserId()->getLicenseExpirationDate());
            }

            $em->persist($order);
            $em->persist($orderItem);

            $subtotal += $orderItem->getTotalPrice();
        }

        return $subtotal;
    }
}

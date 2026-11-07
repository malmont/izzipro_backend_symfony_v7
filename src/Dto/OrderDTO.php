<?php

namespace App\Dto;
use App\Dto\AdressOutputDTO;

class OrderDTO
{
    public int $id;
    public string $reference;
    public float $totalAmount;
    public ?float $subTotal;
    public ?float $totalTax;
    public ?float $shippingCost;
    public string $orderDate;
    public ?int $userId;
    public ?AdressOutputDTO $shippingAdress; 
    public ?string $orderSource;
    public ?string $status;
    public array $orderItems;
    /** Boutique réglable (08/10/2026) : devise du site, identifiant du statut, transporteur */
    public string $currency = 'CAD';
    public ?int $statusId = null;
    public ?array $carrier = null;
    /** Vente au comptoir (C2) : {receiptNumber, creditNoteNumber, cashRegister} ; null pour toute autre commande (clés absentes) */
    public ?array $pos = null;
    /** État du paiement (01/11/2026) : pending, authorized, paid, failed, refunded, canceled (OrderPresenter::paymentStatus) */
    public ?string $paymentStatus = null;
    /** Lignes TTC (F18 partie 2b) : subTotal TTC, priceTax = taxe incluse (information), totalAmount = payé ; faux : totalAmount = subTotal + priceTax */
    public bool $pricesIncludeTax = false;
    /** @var list<array{label: string, rate: ?float, base: ?int, amount: int, total: ?int}> taxes de la commande par taux (cents ; base null sur une commande antérieure sans assiette) */
    public array $taxes = [];

    /** Détail des taxes et drapeau TTC depuis l'entité (F18 partie 2b) */
    public function withTaxDetails(\App\Entity\Order $order): static
    {
        $this->pricesIncludeTax = $order->isPricesIncludeTax();
        $this->taxes = [];
        foreach ($order->getOrderTaxes() as $orderTax) {
            $tax = $orderTax->getTax();
            $amount = (int) round((float) $orderTax->getAmount());
            $base = $orderTax->getBase();
            if ($amount === 0 && ($base ?? 0) === 0) {
                continue; // taxe à 0 : non servie
            }
            $this->taxes[] = ['label' => $tax?->getName() ?? 'Taxe', 'rate' => $tax ? (float) $tax->getRate() : null, 'base' => $base, 'amount' => $amount, 'total' => $base !== null ? $base + $amount : null];
        }

        return $this;
    }
    /** Expédition (01/11/2026) : {carrier, trackingNumber, trackingUrl} dès qu'une étiquette existe, sinon null */
    public ?array $shipment = null;

    /** @var list<array<string, mixed>> retours web (lot 7, 07/11/2026) : [{id, createdAt, amountCents, lines: [{orderItemId, name, quantity, amountCents}]}] */
    public array $returns = [];

    public function __construct(
        int $id,
        string $reference,
        float $totalAmount,
        ?float $subTotal,
        ?float $totalTax,
        ?float $shippingCost,
        string $orderDate,
        ?int $userId,
        ?AdressOutputDTO $shippingAdress, 
        ?string $orderSource,
        ?string $status,
        array $orderItems
    ) {
        $this->id = $id;
        $this->reference = $reference;
        $this->totalAmount = $totalAmount;
        $this->subTotal = $subTotal;
        $this->totalTax = $totalTax;
        $this->shippingCost = $shippingCost;
        $this->orderDate = $orderDate;
        $this->userId = $userId;
        $this->shippingAdress = $shippingAdress;
        $this->orderSource = $orderSource;
        $this->status = $status;
        $this->orderItems = $orderItems;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            // cents entiers (les colonnes sont décimales)
            'totalAmount' => (int) round($this->totalAmount),
            'subTotal' => $this->subTotal === null ? null : (int) round($this->subTotal),
            'priceTax' => $this->totalTax === null ? null : (int) round($this->totalTax),
            'priceShipping' => $this->shippingCost === null ? null : (int) round($this->shippingCost),
            'currency' => $this->currency,
            'statusId' => $this->statusId,
            'carrier' => $this->carrier,
            'orderDate' => $this->orderDate,
            'userId' => $this->userId,
            'shippingAdress' => $this->shippingAdress,
            'orderSource' => $this->orderSource,
            'status' => $this->status,
            'orderItems' => array_map(fn($item) => $item->toArray(), $this->orderItems),
            'paymentStatus' => $this->paymentStatus,
            'pricesIncludeTax' => $this->pricesIncludeTax, // F18 partie 2b
            'taxes' => $this->taxes,
            'shipment' => $this->shipment,
            'returns' => $this->returns,
        ] + ($this->pos ?? []);
    }

    /** @param array<string, \App\Entity\PosReceipt> $receipts genre → numéro */
    public static function posFields(array $receipts): ?array
    {
        if (!$receipts) {
            return null;
        }
        // Ticket, avoir ou ticket carte-cadeau (F9, 27/10/2026 : vente d'une carte, commande de type 3)
        $any = $receipts[\App\Entity\PosReceipt::TICKET] ?? $receipts[\App\Entity\PosReceipt::CREDIT_NOTE] ?? $receipts[\App\Entity\PosReceipt::GIFT_CARD] ?? reset($receipts);

        return [
            'receiptNumber' => isset($receipts[\App\Entity\PosReceipt::TICKET]) ? $receipts[\App\Entity\PosReceipt::TICKET]->getNumber() : null,
            'creditNoteNumber' => isset($receipts[\App\Entity\PosReceipt::CREDIT_NOTE]) ? $receipts[\App\Entity\PosReceipt::CREDIT_NOTE]->getNumber() : null,
            'cashRegister' => ['id' => $any->getCashRegister()->getId(), 'name' => $any->getCashRegister()->getName()],
        ] + (isset($receipts[\App\Entity\PosReceipt::GIFT_CARD]) ? ['giftCardReceiptNumber' => $receipts[\App\Entity\PosReceipt::GIFT_CARD]->getNumber()] : []);
    }
}
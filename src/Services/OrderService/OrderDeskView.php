<?php

namespace App\Services\OrderService;

use App\Security\Entry\ExternalOperator;
use App\Security\Entry\GroupOperator;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Lot CMD-WEB-1 (20/11/2026, contrat validé par le chef) : ce qu'un lecteur reçoit de la fiche d'une commande et de la vue « À traiter »
 * (`OrderDeskService`), construit par LISTE BLANCHE (une clé nouvelle de la fiche n'est jamais servie sans être ajoutée ici).
 * - `full` : un compte administrateur du site, et le super administrateur (son compte, y compris quand il entre sur un site : c'est le même
 *   compte), la fiche entière comme `/admin/commandes/{id}` ;
 * - `limited` : l'agent d'Assistance, l'agence entrée (pendant une assistance) : ni identifiant Stripe, ni
 *   adresse de reçu, ni contenu brut du journal ; la carte en « marque •••• 4 derniers chiffres » ; aucune action (les gestes et leurs
 *   droits sont le lot CMD-WEB) ;
 * - `director` : le directeur entré, en plus : ni courriel ni adresse du client, ni lien d'étiquette (qui porte l'adresse) ; il pilote,
 *   il ne sert pas le client (minimisation, décision du chef).
 */
final class OrderDeskView
{
    public const FULL = 'full';
    public const LIMITED = 'limited';
    public const DIRECTOR = 'director';

    private const ORDER_KEYS = ['id', 'reference', 'totalAmount', 'subTotal', 'priceTax', 'priceShipping', 'currency', 'statusId', 'carrier', 'orderDate', 'orderSource', 'status',
        'orderItems', 'paymentStatus', 'pricesIncludeTax', 'taxes', 'shipment', 'returns', 'receiptNumber', 'creditNoteNumber', 'giftCardReceiptNumber', 'cashRegister'];
    private const ITEM_KEYS = ['id', 'quantity', 'unitPrice', 'totalPrice', 'productId', 'productVariantId', 'productVariantName', 'productSaleUnit', 'productVariantColor',
        'productVariantSize', 'productImage', 'booking', 'options'];
    private const PAYMENT_KEYS = ['id', 'amountCents', 'date', 'method', 'type', 'status', 'stripeStatus', 'card'];
    private const ACTIONS = ['capture', 'refund', 'cancel', 'ship', 'label', 'deliver', 'emails', 'return', 'address'];
    private const TODO_LISTS = ['toShip', 'toCapture', 'incomplete', 'inTransit'];

    public static function audience(?UserInterface $user): string
    {
        if ($user instanceof GroupOperator) {
            return self::DIRECTOR;
        }
        if ($user === null || $user instanceof ExternalOperator) {
            return self::LIMITED;
        }

        return in_array('ROLE_ADMIN', $user->getRoles(), true) ? self::FULL : self::LIMITED;
    }

    /** @param array<string, mixed> $sheet la fiche de `OrderDeskService::sheet()` */
    public static function sheet(array $sheet, string $audience): array
    {
        if ($audience === self::FULL) {
            return $sheet;
        }
        $director = $audience === self::DIRECTOR;
        $order = self::pick($sheet['order'] ?? [], self::ORDER_KEYS);
        $order['orderItems'] = array_map(fn (array $i) => self::pick($i, self::ITEM_KEYS), (array) ($order['orderItems'] ?? []));
        // les niveaux internes aussi, clé par clé (une clé ajoutée plus tard n'est jamais servie sans être nommée ici)
        $order['carrier'] = is_array($order['carrier'] ?? null) ? self::pick($order['carrier'], ['id', 'name', 'kind', 'isPickup']) : null;
        $order['shipment'] = is_array($order['shipment'] ?? null) ? self::pick($order['shipment'], ['carrier', 'trackingNumber', 'trackingUrl']) : null;
        $order['returns'] = array_map(fn (array $r) => self::pick($r, ['id', 'createdAt', 'amountCents'])
            + ['lines' => array_map(fn (array $l) => self::pick($l, ['orderItemId', 'name', 'quantity', 'amountCents']), (array) ($r['lines'] ?? []))], (array) ($order['returns'] ?? []));
        if (isset($order['cashRegister'])) {
            $order['cashRegister'] = self::pick((array) $order['cashRegister'], ['id', 'name']);
        }
        $order['hasShippingAddress'] = ($sheet['order']['shippingAdress'] ?? null) !== null;
        if (!$director) {
            $order['shippingAdress'] = $sheet['order']['shippingAdress'] ?? null;
            $order['userId'] = $sheet['order']['userId'] ?? null;
        }
        $money = $sheet['money'] ?? [];
        $customer = $sheet['customer'] ?? null;

        return [
            'order' => $order,
            'statusId' => $sheet['statusId'] ?? null,
            'statusLabel' => $sheet['statusLabel'] ?? null,
            'currency' => $sheet['currency'] ?? null,
            'money' => [
                'capturedCents' => (int) ($money['capturedCents'] ?? 0), 'refundedCents' => (int) ($money['refundedCents'] ?? 0),
                'refundableCents' => (int) ($money['refundableCents'] ?? 0), 'authorizedCents' => (int) ($money['authorizedCents'] ?? 0),
                'counterSale' => (bool) ($money['counterSale'] ?? false),
                'captured' => array_map(fn (array $c) => ['paymentId' => (int) ($c['paymentId'] ?? 0), 'amountCents' => (int) ($c['amountCents'] ?? 0)], (array) ($money['captured'] ?? [])),
                'authorized' => array_map(fn (array $c) => ['paymentId' => (int) ($c['paymentId'] ?? 0), 'amountCents' => (int) ($c['amountCents'] ?? 0)], (array) ($money['authorized'] ?? [])),
            ],
            'payments' => array_map(fn (array $p) => self::pick($p, self::PAYMENT_KEYS), (array) ($sheet['payments'] ?? [])),
            'labels' => array_map(fn (array $l) => ['index' => $l['index'] ?? null, 'tracking' => $l['tracking'] ?? null, 'weight' => $l['weight'] ?? null] + ($director ? [] : ['url' => $l['url'] ?? null]), (array) ($sheet['labels'] ?? [])),
            'shipment' => self::pick((array) ($sheet['shipment'] ?? []), ['shippedAt', 'carrier', 'trackingNumber', 'trackingUrl']),
            'timeline' => array_map(fn (array $e) => self::pick($e, ['at', 'label', 'kind']), (array) ($sheet['timeline'] ?? [])),
            'journal' => array_map(fn (array $j) => self::pick($j, ['at', 'action', 'by']), (array) ($sheet['journal'] ?? [])),
            'customer' => $customer === null ? null : ($director
                ? ['name' => (string) ($customer['name'] ?? ''), 'ordersCount' => (int) ($customer['ordersCount'] ?? 0)]
                : ['id' => (int) ($customer['id'] ?? 0), 'name' => (string) ($customer['name'] ?? ''), 'email' => (string) ($customer['email'] ?? ''), 'ordersCount' => (int) ($customer['ordersCount'] ?? 0)]),
            'actions' => array_fill_keys(self::ACTIONS, false) + ['counterSale' => (bool) ($sheet['actions']['counterSale'] ?? false)],
            'readOnly' => true,
            'returns' => array_map(fn (array $r) => self::pick($r, ['id', 'createdAt', 'amountCents', 'restock', 'reason', 'by'])
                + ['lines' => array_map(fn (array $l) => self::pick($l, ['orderItemId', 'name', 'quantity', 'amountCents']), (array) ($r['lines'] ?? []))], (array) ($sheet['returns'] ?? [])),
        ];
    }

    /** @param array<string, mixed> $todo la vue de `OrderDeskService::todo()` */
    public static function todo(array $todo, string $audience): array
    {
        if ($audience === self::FULL) {
            return $todo;
        }
        $out = ['counts' => (array) ($todo['counts'] ?? [])];
        foreach (self::TODO_LISTS as $list) {
            $out[$list] = array_map(function (array $r) use ($audience): array {
                $row = self::pick($r, ['id', 'reference', 'date', 'statusId', 'status', 'totalCents', 'shippedAt']);
                if ($audience === self::DIRECTOR) {
                    // le nom seulement : la vue rend le courriel quand le nom manque, il n'est alors pas servi
                    $row['customer'] = ($r['customer'] ?? '') !== '' && ($r['customer'] ?? '') !== ($r['email'] ?? null) ? (string) $r['customer'] : null;
                } else {
                    $row['customer'] = $r['customer'] ?? null;
                    $row['email'] = $r['email'] ?? null;
                }

                return $row;
            }, (array) ($todo[$list] ?? []));
        }
        $out['readOnly'] = true;

        return $out;
    }

    /** @param list<string> $keys */
    private static function pick(array $row, array $keys): array
    {
        return array_intersect_key($row, array_flip($keys));
    }
}

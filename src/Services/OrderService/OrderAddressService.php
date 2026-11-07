<?php

namespace App\Services\OrderService;

use App\Entity\Adress;
use App\Entity\Order;
use App\Services\ContentAuditService\ContentAuditRecorder;
use App\Services\TenantEntityManagerProvider;

/**
 * Changement de l'adresse de livraison d'une commande web avant expédition (lot 7, 07/11/2026) : une nouvelle ligne d'adresse est
 * créée pour la commande (le carnet d'adresses du client n'est pas modifié) ; frais de livraison et taxes déjà payés restent tels quels
 * (un écart se règle par « Rembourser » ou hors ligne). Journalisé.
 */
class OrderAddressService
{
    public const MESSAGES = [
        'COUNTER_SALE' => 'Vente au comptoir : aucune adresse de livraison.',
        'ALREADY_SHIPPED' => 'Commande expédiée, livrée ou annulée : l\'adresse ne peut plus changer.',
        'VALIDATION' => 'Adresse incomplète.',
    ];
    public const FIELDS = ['firstname', 'lastname', 'company', 'address', 'complement', 'city', 'province', 'codepostal', 'country', 'phone'];
    private const REQUIRED = ['firstname', 'lastname', 'address', 'city', 'codepostal', 'country'];

    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly ContentAuditRecorder $audit)
    {
    }

    public function blocker(Order $order): ?string
    {
        if ($order->getOrderSource()?->getId() === 2) {
            return 'COUNTER_SALE';
        }

        return in_array((int) ($order->getStatus()?->getId() ?? 0), [OrderShipmentService::STATUS_SHIPPED, OrderShipmentService::STATUS_DELIVERED, OrderShipmentService::STATUS_CANCELLED], true) ? 'ALREADY_SHIPPED' : null;
    }

    /** @return array<string, string> valeurs actuelles pour préremplir le formulaire */
    public function current(Order $order): array
    {
        $a = $order->getShippingAdress();
        $out = array_fill_keys(self::FIELDS, '');
        if ($a !== null) {
            $out = ['firstname' => (string) $a->getFirstname(), 'lastname' => (string) $a->getLastname(), 'company' => (string) $a->getCompany(), 'address' => (string) $a->getAddress(), 'complement' => (string) $a->getComplement(),
                'city' => (string) $a->getCity(), 'province' => (string) $a->getProvince(), 'codepostal' => (string) $a->getCodepostal(), 'country' => (string) $a->getCountry(), 'phone' => (string) $a->getPhone()];
        }

        return $out;
    }

    /** @param array<string, mixed> $input @return array{status: int, body: array<string, mixed>} */
    public function change(Order $order, array $input, ?string $by): array
    {
        $blocker = $this->blocker($order);
        if ($blocker !== null) {
            return ['status' => 409, 'body' => ['error' => $blocker, 'message' => self::MESSAGES[$blocker]]];
        }
        $values = [];
        $errors = [];
        foreach (self::FIELDS as $f) {
            $values[$f] = mb_substr(trim((string) ($input[$f] ?? '')), 0, $f === 'address' || $f === 'complement' ? 255 : 120);
            if (in_array($f, self::REQUIRED, true) && $values[$f] === '') {
                $errors[] = ['path' => $f, 'message' => 'obligatoire'];
            }
        }
        $values['country'] = strtoupper($values['country']);
        if (strlen($values['country']) !== 2 && $errors === []) {
            $errors[] = ['path' => 'country', 'message' => 'code pays ISO à deux lettres attendu (CA, FR…)'];
        }
        if ($errors !== []) {
            return ['status' => 422, 'body' => ['error' => 'VALIDATION', 'message' => self::MESSAGES['VALIDATION'], 'errors' => $errors]];
        }
        $em = $this->emProvider->getEntityManager();
        $before = $this->current($order);
        $address = (new Adress())->setFirstname($values['firstname'])->setLastname($values['lastname'])->setFullname(trim($values['firstname'] . ' ' . $values['lastname']))->setCompany($values['company'] ?: null)
            ->setAddress($values['address'])->setComplement($values['complement'] ?: null)->setCity($values['city'])->setProvince($values['province'] ?: null)->setCodepostal($values['codepostal'])->setCountry($values['country'])
            ->setPhone($values['phone'] !== '' ? $values['phone'] : (string) ($order->getShippingAdress()?->getPhone() ?? ''))->setUserAdress($order->getUserId());
        $em->persist($address);
        $order->setShippingAdress($address);
        $em->flush();
        $this->audit->record('order', (int) $order->getId(), 'address', $before, $values + ['addressId' => (int) $address->getId(), 'by' => $by], ['shippingAdress']);

        return ['status' => 200, 'body' => ['addressId' => (int) $address->getId(), 'address' => $values]];
    }
}

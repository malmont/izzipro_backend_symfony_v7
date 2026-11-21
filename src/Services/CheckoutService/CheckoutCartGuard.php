<?php

namespace App\Services\CheckoutService;

use App\Entity\Adress;
use App\Entity\User;
use App\Services\OrderService\TaxEngine;

/**
 * Lot CHECKOUT-PANIER (21/11/2026, faille relevée par la relecture de FICHES-HORS-LIGNE) : une commande web se crée TOUJOURS
 * depuis le panier coté et payé, gardé avec l'intention de paiement (`CheckoutSession::getCart()`), jamais depuis le corps
 * envoyé à la commande. Avant ce lot, seul le MONTANT liait la commande au paiement : un client payait un article et
 * commandait un autre article au même total (hors ligne, autre variante, article de caisse seule, autre transporteur).
 *
 * Le corps n'est plus qu'un CONTRÔLE : comparé au panier sur des lignes normalisées (variante, quantité, personnalisation ;
 * pour une location : début, fin, forfait, type de durée, passagers ; l'ordre des lignes et les champs en plus ne comptent
 * pas), puis sur le transporteur, les frais de livraison et l'adresse de livraison quand le corps les donne.
 */
final class CheckoutCartGuard
{
    /** Clés du panier gardé avec l'intention (PrepareCheckoutUseCase) */
    public const CART_KEYS = ['items', 'carrierId', 'priceShipping', 'shippingPrice', 'service', 'shippingAddress', 'booking', 'rental'];
    /** Clés de panier qu'une commande prend TOUJOURS du panier payé, jamais du corps (alias `rental` compris) ; l'adresse vient du corps, comparée */
    public const ORDER_CART_KEYS = ['items', 'carrierId', 'priceShipping', 'shippingPrice', 'service', 'booking', 'rental'];

    /** Le corps donne-t-il le même panier que celui qui a été coté et payé ? */
    public static function sameCart(array $body, array $cart, ?\DateTimeZone $storeTimezone = null): bool
    {
        if (self::lines($body, $storeTimezone) !== self::lines($cart, $storeTimezone)) {
            return false;
        }
        // les frais de livraison ne se comparent plus : ils appartiennent au serveur (panier gardé, lot CHECKOUT-LIVRAISON)
        return !array_key_exists('carrierId', $body) || self::carrier($body) === self::carrier($cart);
    }

    /**
     * L'adresse de livraison de la commande est-elle celle qui a servi au devis ? (taxes et livraison en dépendent)
     * Seuls les éléments connus au devis comptent : pays, province, code postal (la ville et la rue ne changent aucun montant).
     *
     * Adresse SAISIE dans le corps : stricte (un élément connu au devis doit y être, et le même). Fiche du COMPTE
     * (`$fromAccount`) : un élément absent de la fiche est toléré (code postal non renseigné), un élément différent ne l'est pas.
     *
     * @param array<string, mixed>|null $orderAddress adresse envoyée (shippingAddress) ou relue (fiche du compte)
     */
    public static function sameAddress(?array $cartAddress, ?array $orderAddress, bool $fromAccount = false): bool
    {
        $quoted = self::address($cartAddress);
        if ($quoted === null) {
            return true; // devis sans adresse (adresse du magasin) : rien à comparer
        }
        if ($orderAddress === null) {
            return true; // corps SANS adresse : un champ manquant, refusé par la validation de la commande (GUEST_FIELDS_REQUIRED…), pas un écart
        }
        $ordered = self::address($orderAddress) ?? []; // adresse donnée mais illisible (sans pays ni province) : comparée comme vide
        foreach ($quoted as $key => $value) {
            if ($value === null || (($ordered[$key] ?? null) === null && $fromAccount)) {
                continue;
            }
            if (($ordered[$key] ?? null) !== $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * L'adresse de livraison d'un corps de commande : la fiche du compte (addressId, si elle est bien au client), sinon l'adresse
     * envoyée ; null pour un retrait en magasin. La même lecture pour le devis de l'intention et pour la commande.
     *
     * @param \Doctrine\Persistence\ObjectRepository<Adress> $addresses
     * @return array{country: mixed, province: mixed, city: mixed, postalCode: mixed}|null
     */
    public static function orderAddress(array $order, ?User $user, \Doctrine\Persistence\ObjectRepository $addresses): ?array
    {
        if ($user !== null && is_numeric($order['addressId'] ?? null)) {
            $address = $addresses->find((int) $order['addressId']);
            if ($address instanceof Adress && $address->getUserAdress()?->getId() === $user->getId()) {
                return ['country' => $address->getCountry(), 'province' => $address->getProvince(), 'city' => $address->getCity(), 'postalCode' => $address->getCodepostal()];
            }
        }
        $shipping = is_array($order['shippingAddress'] ?? null) ? $order['shippingAddress'] : null;

        // lues comme l'adresse ENREGISTRÉE de la commande (CheckoutCustomerService::address) : ce qui est comparé est ce qui est gardé
        return $shipping === null ? null : [
            'country' => $shipping['country'] ?? null, 'province' => $shipping['province'] ?? $shipping['state'] ?? null, 'city' => $shipping['city'] ?? null,
            'postalCode' => $shipping['zipCode'] ?? $shipping['zip'] ?? $shipping['postalCode'] ?? $shipping['postcode'] ?? $shipping['codepostal'] ?? null,
        ];
    }

    /**
     * Le corps de commande dont les clés de panier sont TOUTES celles du panier payé : les clés de panier du corps sont retirées
     * (aucune ne passe, même absente du panier), et la réservation globale du panier est dépliée sur les lignes qui n'en ont pas,
     * comme le devis (CartQuoteInputDto::fromArray), pour que la commande la reçoive.
     *
     * @return array<string, mixed>
     */
    public static function withPaidCart(array $cart, array $body): array
    {
        $paid = array_intersect_key($cart, array_flip(self::ORDER_CART_KEYS));
        $global = is_array($cart['booking'] ?? $cart['rental'] ?? null) && ($cart['booking'] ?? $cart['rental']) !== [] ? ($cart['booking'] ?? $cart['rental']) : null;
        if ($global !== null && is_array($paid['items'] ?? null)) {
            foreach ($paid['items'] as $i => $item) {
                if (is_array($item) && !isset($item['booking']) && !isset($item['rental'])) {
                    $paid['items'][$i]['booking'] = $global;
                }
            }
        }

        return $paid + array_diff_key($body, array_flip(self::ORDER_CART_KEYS));
    }

    /** Le corps de commande à garder avec l'intention : sans ses clés de panier (la commande les prendra du panier) */
    public static function withoutCart(array $order): array
    {
        return array_diff_key($order, array_flip(self::ORDER_CART_KEYS));
    }

    /** @return list<string> lignes normalisées et triées (une chaîne par ligne) */
    public static function lines(array $data, ?\DateTimeZone $storeTimezone = null): array
    {
        $global = is_array($data['booking'] ?? $data['rental'] ?? null) ? ($data['booking'] ?? $data['rental']) : null;
        $lines = [];
        foreach (is_array($data['items'] ?? null) ? $data['items'] : [] as $item) {
            if (!is_array($item)) {
                $lines[] = 'invalide';
                continue;
            }
            $booking = $item['booking'] ?? $item['rental'] ?? $global;
            $lines[] = (string) json_encode([
                'variant' => is_numeric($item['productVariantId'] ?? $item['variantId'] ?? null) ? (int) ($item['productVariantId'] ?? $item['variantId']) : null,
                'quantity' => is_numeric($item['quantity'] ?? null) ? (int) $item['quantity'] : null,
                'customization' => is_numeric($item['customizationId'] ?? null) ? (int) $item['customizationId'] : null,
                'booking' => is_array($booking) && $booking !== [] ? [
                    'start' => self::instant($booking['start'] ?? null, $storeTimezone), 'end' => self::instant($booking['end'] ?? null, $storeTimezone),
                    'rateId' => is_numeric($booking['rateId'] ?? $booking['rentalPackId'] ?? null) ? (int) ($booking['rateId'] ?? $booking['rentalPackId']) : null,
                    'durationType' => (string) ($booking['durationType'] ?? $booking['duration_type'] ?? ''),
                    'passengers' => (int) ($booking['passengers'] ?? 0),
                ] : null,
            ]);
        }
        sort($lines);

        return $lines;
    }

    /** Un instant comparable : la même heure écrite autrement (« Z » ou décalage, millisecondes) donne la même valeur */
    private static function instant(mixed $value, ?\DateTimeZone $storeTimezone): string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return '';
        }
        try {
            // sans fuseau : l'heure du magasin, comme le devis (BookingClock) ; avec « Z » ou un décalage : l'instant écrit
            return (string) (new \DateTimeImmutable($text, $storeTimezone ?? new \DateTimeZone('UTC')))->getTimestamp();
        } catch (\Exception) {
            return $text;
        }
    }

    private static function carrier(array $data): ?int
    {
        return is_numeric($data['carrierId'] ?? null) && (int) $data['carrierId'] > 0 ? (int) $data['carrierId'] : null;
    }


    /** @return array{country: ?string, province: ?string, postalCode: ?string}|null */
    private static function address(?array $raw): ?array
    {
        $normalized = is_array($raw) ? TaxEngine::normalizeAddress($raw) : null;
        if ($normalized === null) {
            return null;
        }
        $postal = $normalized['postalCode'] !== null ? strtoupper(str_replace([' ', '-'], '', $normalized['postalCode'])) : null;
        if ($postal !== null && $normalized['country'] === 'US') {
            $postal = substr($postal, 0, 5); // ZIP+4 et ZIP désignent la même zone de taxes
        }

        return ['country' => $normalized['country'], 'province' => $normalized['province'], 'postalCode' => $postal !== '' ? $postal : null];
    }
}

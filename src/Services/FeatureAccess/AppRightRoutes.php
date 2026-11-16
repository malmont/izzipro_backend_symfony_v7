<?php

namespace App\Services\FeatureAccess;

/**
 * Lot ENT-1 (16/11/2026, contrat validé par le chef de projet) : le DROIT d'usage de la caisse (`module.pos`) et de l'e-commerce mobile
 * (`app.mobile_shop`) garde leurs routes, sans jamais couper une vente commencée.
 *
 * CAISSE : chaque route de la caisse est rangée ici. Un DÉBUT (ouverture de session, nouveau paiement, nouvelle vente, échange,
 * caution, carte-cadeau vendue) est refusé sans droit ; une SUITE (fin d'un paiement déjà autorisé, fermeture de session, rapports,
 * reçus, remboursements, ventes hors ligne déjà encaissées, PIN, lectures) est toujours servie. Une route de la caisse que cette liste
 * ne range pas est traitée comme un début : fermé par défaut (l'essai énumère toutes les routes et échoue sur une route non rangée).
 *
 * E-COMMERCE MOBILE : rien ne distingue une requête de l'application mobile de celle du web, sauf l'en-tête `X-App: mobile` que
 * l'application envoie. Avec cet en-tête, les mêmes débuts de parcours que la boutique (devis du panier, intention de paiement,
 * nouvel abonnement) exigent le droit de l'application mobile. Ce n'est pas une preuve (c'est notre application qui l'envoie) : sans
 * l'en-tête, une application ancienne est servie comme le web ; l'activation d'une nouvelle clé mobile, elle, est refusée sans droit.
 */
final class AppRightRoutes
{
    public const START = 'start';
    public const SERVED = 'served';
    public const POS_PATHS = '#^/api/(caisse|pos|cash-registers)(/|$)|^/api/order/create-multi-payment$#';
    public const MOBILE_HEADER = 'X-App';

    /** @var list<array{0: string, 1: string}> débuts de la caisse : [méthode, expression du chemin] */
    public const POS_STARTS = [
        ['POST', '#^/api/caisse/open$#'],
        ['POST', '#^/api/pos/terminal/payments$#'],
        ['POST', '#^/api/pos/terminal/connection-token$#'],
        ['POST', '#^/api/order/create-multi-payment$#'], // sauf la suite d'un paiement déjà autorisé (lue dans le corps)
        ['POST', '#^/api/pos/exchanges$#'],
        ['POST', '#^/api/pos/rentals/deposits$#'],
        ['POST', '#^/api/pos/gift-cards$#'], // sauf la suite d'un paiement déjà autorisé (lue dans le corps)
    ];

    /** @var list<array{0: string, 1: string}> toujours servis : [méthode (« GET » couvre aussi HEAD), expression du chemin] */
    public const POS_SERVED = [
        ['GET', '#^/api/(caisse|pos|cash-registers)(/|$)#'],
        ['POST', '#^/api/caisse/(close|deposit|withdraw|cashfunddeposit|cashfundwithdraw|drawer-open)$#'],
        ['POST', '#^/api/cash-registers$#'],
        ['PUT', '#^/api/cash-registers/[^/]+$#'],
        ['POST', '#^/api/pos/(approvals|pin|pin-login|unlock|quote|returns|offline-sales|pertes/articles-non-lus|exchanges/quote)$#'],
        ['POST', '#^/api/pos/coupons/(parse|claims/mark)$#'],
        ['POST', '#^/api/pos/customers$#'],
        ['POST', '#^/api/pos/customers/[^/]+/(erase|link|loyalty/adjust|merge)$#'],
        ['PATCH', '#^/api/pos/customers/[^/]+$#'],
        ['POST', '#^/api/pos/gift-cards/replace$#'],
        ['POST', '#^/api/pos/gift-cards/[^/]+/(adjust|cancel|cash-out)$#'],
        ['POST', '#^/api/pos/receipts/(email|reprint|revoke-link)$#'],
        ['POST', '#^/api/pos/rentals/bookings/[^/]+/return$#'],
        ['POST', '#^/api/pos/rentals/deposits/[^/]+/return$#'],
        ['POST', '#^/api/pos/terminal/payments/[^/]+/(cancel|refund|refund/confirm|simulate)$#'],
        ['PUT', '#^/api/pos/favorites/pins$#'],
    ];

    /** @var list<array{0: string, 1: string}> débuts de parcours de l'application mobile (avec l'en-tête X-App: mobile) */
    public const MOBILE_STARTS = [
        ['POST', '#^/api/cart/quote$#'],
        ['POST', '#^/api/stripe/create-intent$#'],
        ['POST', '#^/api/subscriptions$#'],
        ['POST', '#^/api/subscriptions/\d+/(change-plan|resume)$#'],
    ];

    /**
     * Le rang d'une route de la caisse : START (refusée sans droit), SERVED (toujours servie), ou null (pas une route de la caisse).
     * Une route de la caisse non rangée vaut START (fermé par défaut).
     *
     * @param array<mixed>|null $body le corps JSON décodé de la requête
     */
    public static function pos(string $method, string $path, ?array $body = null): ?string
    {
        if (preg_match(self::POS_PATHS, $path) !== 1) {
            return null;
        }
        $method = strtoupper($method) === 'HEAD' ? 'GET' : strtoupper($method);
        foreach (self::POS_STARTS as [$m, $pattern]) {
            if ($m === $method && preg_match($pattern, $path) === 1) {
                return self::continuesAuthorizedPayment($path, $body) ? self::SERVED : self::START;
            }
        }

        return self::classified($method, $path) ? self::SERVED : self::START;
    }

    /** La route est-elle rangée dans les débuts ou les toujours servis ? (l'essai l'exige de chaque route de la caisse) */
    public static function classified(string $method, string $path): bool
    {
        $method = strtoupper($method) === 'HEAD' ? 'GET' : strtoupper($method);
        foreach ([...self::POS_STARTS, ...self::POS_SERVED] as [$m, $pattern]) {
            if ($m === $method && preg_match($pattern, $path) === 1) {
                return true;
            }
        }

        return false;
    }

    /** Un début de parcours de l'application mobile, quand la requête porte l'en-tête de l'application mobile */
    public static function mobileStart(string $method, string $path, ?string $appHeader): bool
    {
        if (strtolower(trim((string) $appHeader)) !== 'mobile') {
            return false;
        }
        foreach (self::MOBILE_STARTS as [$m, $pattern]) {
            if ($m === strtoupper($method) && preg_match($pattern, $path) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * La SUITE d'un paiement déjà autorisé : une vente dont une part carte porte la référence du paiement fait au terminal
     * (`paymentMethods[].terminalPaymentId` ou `stripePaymentId`), ou une carte-cadeau vendue avec `terminalPaymentId`. Le paiement a
     * commencé avant la fin du droit : il se termine.
     *
     * @param array<mixed>|null $body
     */
    private static function continuesAuthorizedPayment(string $path, ?array $body): bool
    {
        if (!is_array($body)) {
            return false;
        }
        if ($path === '/api/order/create-multi-payment') {
            foreach (is_array($body['paymentMethods'] ?? null) ? $body['paymentMethods'] : [] as $method) {
                if (is_array($method) && (self::filled($method['terminalPaymentId'] ?? null) || self::filled($method['stripePaymentId'] ?? null))) {
                    return true;
                }
            }

            return false;
        }

        return $path === '/api/pos/gift-cards' && self::filled($body['terminalPaymentId'] ?? null);
    }

    private static function filled(mixed $value): bool
    {
        return is_scalar($value) && trim((string) $value) !== '';
    }
}

<?php

namespace App\Services\FeatureAccess;

use App\Entity\PosTerminalPayment;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\HttpFoundation\Request;

/**
 * Lot ENT-1 (relecture indépendante) : une route de la caisse rangée « début » peut être, en fait, la SUITE d'une vente commencée
 * avant la fin du droit. Elle est alors servie : jamais de coupure au milieu d'une vente. La suite se reconnaît par des faits
 * VÉRIFIÉS, jamais par la seule présence d'un champ :
 * - un retour libre (`create-multi-payment` avec `typeOrder` autre que 1) ;
 * - le rejeu d'une requête déjà reçue (en-tête `Idempotency-Key` déjà connu : la réponse rejouée, pas une nouvelle vente) ;
 * - toute référence de paiement au terminal du corps (`terminalPaymentId`, `stripePaymentId`, à n'importe quel niveau : vente, carte-
 *   cadeau, échange, caution), à condition que CHAQUE référence désigne un paiement au terminal du site, pas encore rattaché à une
 *   vente, ni échoué ni annulé. Une fausse référence ne vaut rien : la requête reste un début, refusé sans droit.
 * Le corps n'est lu qu'ici, quand le module est éteint.
 */
class PosRightGuard
{
    private const REFERENCE_KEYS = ['terminalPaymentId', 'stripePaymentId'];

    public function __construct(private readonly TenantEntityManagerProvider $emProvider)
    {
    }

    public function continues(Request $request, string $path): bool
    {
        $connection = $this->emProvider->getEntityManager()->getConnection();
        $key = trim((string) $request->headers->get('Idempotency-Key'));
        if ($key !== '' && (int) $connection->fetchOne('SELECT COUNT(*) FROM pos_idempotency_key WHERE idem_key = ?', [$key]) > 0) {
            return true;
        }
        $body = json_decode((string) $request->getContent(), true);
        if (!is_array($body)) {
            return false;
        }
        if ($path === '/api/order/create-multi-payment' && (int) ($body['typeOrder'] ?? 1) !== 1) {
            return true; // retour libre en caisse (typeOrder 2) : le contrat sert les retours
        }
        $references = self::references($body);
        if ($references === []) {
            return false;
        }
        foreach ($references as $reference) {
            $column = ctype_digit($reference) ? 'id' : 'stripe_payment_intent_id';
            $found = $connection->fetchOne(
                "SELECT COUNT(*) FROM pos_terminal_payment WHERE $column = ? AND order_id IS NULL AND status NOT IN (?, ?)",
                [$column === 'id' ? (int) $reference : $reference, PosTerminalPayment::FAILED, PosTerminalPayment::CANCELED]
            );
            if ((int) $found === 0) {
                return false; // une seule référence inconnue, déjà utilisée ou échouée : un début
            }
        }

        return true;
    }

    /**
     * Les références de paiement au terminal d'un corps, à toute profondeur.
     *
     * @param array<mixed> $node
     * @return list<string>
     */
    public static function references(array $node): array
    {
        $found = [];
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $found = [...$found, ...self::references($value)];
            } elseif (in_array($key, self::REFERENCE_KEYS, true) && is_scalar($value) && trim((string) $value) !== '') {
                $found[] = trim((string) $value);
            }
        }

        return $found;
    }
}

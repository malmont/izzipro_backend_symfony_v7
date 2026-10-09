<?php

namespace App\Repository;

use App\Entity\CheckoutSession;
use Doctrine\ORM\EntityRepository;

/** @extends EntityRepository<CheckoutSession> */
class CheckoutSessionRepository extends EntityRepository
{
    public function findOneByPaymentIntent(string $paymentIntentId): ?CheckoutSession
    {
        return $this->findOneBy(['paymentIntentId' => $paymentIntentId]);
    }

    /**
     * Réservation atomique de la création de commande : passe open (ou failed, nouvel essai) à processing. Un seul
     * appelant obtient true ; une réservation de plus de 2 minutes (processus interrompu) peut être reprise.
     */
    public function claim(string $paymentIntentId): bool
    {
        return $this->getEntityManager()->getConnection()->executeStatement(
            "UPDATE checkout_session SET status = 'processing', updated_at = NOW()
             WHERE payment_intent_id = :id AND (status IN ('open', 'failed') OR (status = 'processing' AND updated_at < NOW() - INTERVAL '2 minutes'))",
            ['id' => $paymentIntentId]
        ) === 1;
    }
}

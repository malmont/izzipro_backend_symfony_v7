<?php

namespace App\MemoiresVivantes\Services;

use App\Entity\StripeConfig;
use App\Services\TenantEntityManagerProvider;
use Stripe\Checkout\Session;
use Stripe\Stripe;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Lot WEBHOOK-SIG (20/11/2026, faille de sécurité) : la session Stripe Checkout d'un livre, RELUE chez Stripe avant de le marquer payé.
 * Même compte et même clé que sa création (BookPaymentService : clé du serveur, compte connecté du site `stripe_config.account_id`) ;
 * STRIPE-ALIGN unifiera ensuite le choix du compte avec le résolveur commun. Remplaçable dans les essais (aucun appel réel à Stripe).
 */
class BookCheckoutSessionReader
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        #[Autowire('%env(default::STRIPE_SECRET_KEY)%')]
        private readonly ?string $stripeSecretKey = null,
    ) {
    }

    /**
     * @return array<string, mixed>|null la session telle que Stripe la connaît ; null si le site n'a pas de compte ou si Stripe ne la
     *                                   connaît pas
     */
    public function retrieve(string $sessionId): ?array
    {
        $config = $this->emProvider->getEntityManager()->getRepository(StripeConfig::class)->findOneBy([]);
        if ($config === null || !$config->getAccountId() || $this->stripeSecretKey === null || $this->stripeSecretKey === '') {
            return null;
        }
        try {
            Stripe::setApiKey($this->stripeSecretKey);

            return Session::retrieve($sessionId, ['stripe_account' => $config->getAccountId()])->toArray();
        } catch (\Stripe\Exception\InvalidRequestException $e) {
            if ($e->getStripeCode() === 'resource_missing') {
                return null;
            }
            throw $e;
        }
    }
}

<?php

namespace App\Services\PosStripeService;

use App\Entity\AddressEntreprise;
use App\Entity\CashRegister;
use App\Entity\Entreprise;
use App\Entity\PosTerminalPayment;
use App\Entity\StripeConfig;
use App\Entity\User;
use App\Services\CaisseService\CashRegisterException;
use App\Services\PosAuditService\PosAuditService;
use App\Services\StripeService\StripeAccountResolver;
use App\Services\StripeService\StripeService;
use App\Services\TenantEntityManagerProvider;

/**
 * Paiements de caisse en production (M1) : mode Stripe de la caisse par site (test par défaut | réel), compte Connect réel distinct
 * (inscription par lien Stripe, état : pièces manquantes, encaissement, virements). Passage en réel seulement si les clés réelles sont
 * configurées, si le compte réel du site peut encaisser ET recevoir les virements, et si aucun paiement par terminal n'est en cours ;
 * les lecteurs appairés dans l'autre mode sont détachés (un lecteur appartient à un compte et à un mode), un lecteur simulé n'existe
 * jamais en réel. Journal STRIPE_MODE_CHANGED, STRIPE_ACCOUNT_UPDATED. La boutique en ligne n'est pas concernée.
 */
class PosStripeModeService
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly StripeAccountResolver $accounts,
        private readonly PosStripeAccountGatewayInterface $gateway,
        private readonly StripeService $stripe,
        private readonly PosAuditService $audit,
    ) {
    }

    public function mode(): string
    {
        return $this->accounts->mode(StripeAccountResolver::CHANNEL_POS);
    }

    /**
     * État complet pour la page EasyAdmin et la surveillance : mode, clés configurées (jamais leur valeur), compte de chaque mode,
     * emplacements Terminal, postes et lecteurs, paiements en cours. $withStripe = false : sans appel Stripe.
     */
    public function overview(bool $withStripe = true): array
    {
        $mode = $this->mode();
        $internal = $this->accounts->isInternal();
        $out = ['mode' => $mode, 'internal' => $internal, 'keys' => ['test' => $this->accounts->keysConfigured('test'), 'live' => $this->accounts->keysConfigured('live')], 'accounts' => []];
        foreach ([StripeAccountResolver::TEST, StripeAccountResolver::LIVE] as $m) {
            $id = $internal ? null : $this->accounts->accountId($m);
            $account = ['configured' => $internal || $id !== null, 'id' => StripeAccountResolver::mask($id), 'active' => $this->accounts->accountOptions($m) !== null,
                'terminalLocation' => $this->accounts->terminalLocationId($m) !== null, 'status' => null];
            if ($withStripe && $account['configured'] && $this->accounts->keysConfigured($m)) {
                try {
                    $account['status'] = $this->gateway->retrieveAccount($m, $id);
                    unset($account['status']['id']);
                } catch (CashRegisterException $e) {
                    $account['status'] = ['error' => $e->getMessage()];
                }
            }
            $out['accounts'][$m] = $account;
        }
        $em = $this->emProvider->getEntityManager();
        $out['registers'] = array_map(fn (CashRegister $r) => ['id' => $r->getId(), 'name' => $r->getName(), 'active' => $r->isActive(), 'terminalMode' => $r->effectiveTerminalMode(),
            'reader' => $r->getStripeReaderId() ? ['label' => $r->getStripeReaderLabel(), 'simulated' => $r->isStripeReaderSimulated(), 'enabled' => $r->isTerminalEnabled()] : null],
            $em->getRepository(CashRegister::class)->findAllOrdered(false));
        $out['pendingPayments'] = $this->pendingCount();

        return $out;
    }

    /** Lien d'inscription Stripe du compte RÉEL du site (créé au besoin) ; journal STRIPE_ACCOUNT_UPDATED */
    public function liveOnboardingLink(string $refreshUrl, string $returnUrl, ?User $user): string
    {
        if ($this->accounts->isInternal()) {
            throw new CashRegisterException('STRIPE_INTERNAL_SITE', 'Site interne : il encaisse sur le compte de la plateforme, sans inscription Connect', 409);
        }
        if (!$this->accounts->keysConfigured(StripeAccountResolver::LIVE)) {
            throw new CashRegisterException('STRIPE_KEYS_MISSING', 'Clés Stripe réelles de la caisse non configurées sur le serveur (STRIPE_LIVE_SECRET_KEY)', 409);
        }
        $em = $this->emProvider->getEntityManager();
        $config = $this->accounts->config();
        if ($config === null) {
            $config = (new StripeConfig())->setIsActive(false);
            $em->persist($config);
        }
        if ($config->getLiveAccountId() === null) {
            $email = $em->getRepository(AddressEntreprise::class)->findOneBy([])?->getEmail();
            $config->setLiveAccountId($this->gateway->createExpressAccount(StripeAccountResolver::LIVE, $this->stripe->resolveTenantCountryCode(), $email ?: null))->setLiveIsActive(false);
            $em->flush();
            $this->audit->record(PosAuditService::STRIPE_ACCOUNT_UPDATED, ['action' => 'live_account_created', 'account' => StripeAccountResolver::mask($config->getLiveAccountId())], null, null, null, null, $user);
        }
        $url = $this->gateway->createOnboardingLink(StripeAccountResolver::LIVE, (string) $config->getLiveAccountId(), $refreshUrl, $returnUrl);
        $this->audit->record(PosAuditService::STRIPE_ACCOUNT_UPDATED, ['action' => 'onboarding_link', 'account' => StripeAccountResolver::mask($config->getLiveAccountId())], null, null, null, null, $user);

        return $url;
    }

    /** Relit le compte réel chez Stripe : actif si encaissement ET virements autorisés ; journal si l'état change */
    public function refreshLiveAccount(?User $user): ?array
    {
        $config = $this->accounts->config();
        if ($config?->getLiveAccountId() === null || !$this->accounts->keysConfigured(StripeAccountResolver::LIVE)) {
            return null;
        }
        $status = $this->gateway->retrieveAccount(StripeAccountResolver::LIVE, $config->getLiveAccountId());
        $ready = $status['chargesEnabled'] && $status['payoutsEnabled'];
        if ($ready !== $config->isLiveActive()) {
            $config->setLiveIsActive($ready);
            $this->emProvider->getEntityManager()->flush();
            $this->audit->record(PosAuditService::STRIPE_ACCOUNT_UPDATED, ['action' => 'live_account_status', 'account' => StripeAccountResolver::mask($config->getLiveAccountId()),
                'chargesEnabled' => $status['chargesEnabled'], 'payoutsEnabled' => $status['payoutsEnabled'], 'active' => $ready], null, null, null, null, $user);
        }

        return $status;
    }

    /**
     * Passage test ↔ réel. Réel : clés réelles configurées, compte réel (ou compte de la plateforme pour un site interne) qui peut encaisser
     * et recevoir les virements (relu chez Stripe à l'instant), aucun paiement par terminal en cours. Les lecteurs appairés sont détachés.
     */
    public function switchMode(string $target, ?User $user): array
    {
        $target = $target === StripeAccountResolver::LIVE ? StripeAccountResolver::LIVE : StripeAccountResolver::TEST;
        $from = $this->mode();
        if ($target === $from) {
            return ['mode' => $from, 'changed' => false, 'detachedReaders' => []];
        }
        if (!$this->accounts->keysConfigured($target)) {
            throw new CashRegisterException('STRIPE_KEYS_MISSING', $target === StripeAccountResolver::LIVE ? 'Clés Stripe réelles de la caisse non configurées sur le serveur (STRIPE_LIVE_SECRET_KEY)' : 'Clé Stripe de test non configurée sur le serveur', 409);
        }
        if ($this->pendingCount() > 0) {
            throw new CashRegisterException('TERMINAL_PAYMENTS_PENDING', 'Paiements par terminal en cours (ou remboursement Interac en attente) : terminez-les avant de changer de mode', 409, [], ['pending' => $this->pendingCount()]);
        }
        $account = null;
        if ($target === StripeAccountResolver::LIVE) {
            $id = $this->accounts->isInternal() ? null : $this->accounts->accountId(StripeAccountResolver::LIVE);
            if (!$this->accounts->isInternal() && $id === null) {
                throw new CashRegisterException('STRIPE_ACCOUNT_NOT_READY', 'Aucun compte Stripe réel : lancez l\'inscription Stripe du magasin', 409, [], ['chargesEnabled' => false, 'payoutsEnabled' => false]);
            }
            $account = $this->gateway->retrieveAccount(StripeAccountResolver::LIVE, $id);
            if (!$account['chargesEnabled'] || !$account['payoutsEnabled']) {
                if (!$this->accounts->isInternal()) {
                    $this->accounts->config()?->setLiveIsActive(false);
                    $this->emProvider->getEntityManager()->flush();
                }
                throw new CashRegisterException('STRIPE_ACCOUNT_NOT_READY', 'Le compte Stripe réel du magasin ne peut pas encore encaisser ou recevoir les virements : terminez l\'inscription', 409, [],
                    ['chargesEnabled' => $account['chargesEnabled'], 'payoutsEnabled' => $account['payoutsEnabled'], 'currentlyDue' => $account['currentlyDue'], 'disabledReason' => $account['disabledReason']]);
            }
            if (!$this->accounts->isInternal()) {
                $this->accounts->config()?->setLiveIsActive(true);
            }
        }
        $em = $this->emProvider->getEntityManager();
        $detached = [];
        foreach ($em->getRepository(CashRegister::class)->findAll() as $register) {
            if ($register->getStripeReaderId() !== null) {
                $detached[] = ['cashRegisterId' => $register->getId(), 'label' => $register->getStripeReaderLabel(), 'simulated' => $register->isStripeReaderSimulated()];
                $register->setStripeReaderId(null)->setStripeReaderLabel(null)->setStripeReaderSimulated(false)->setTerminalEnabled(false);
            }
        }
        $em->getRepository(Entreprise::class)->findOneBy([])?->setPosStripeMode($target);
        $em->flush();
        $this->audit->record(PosAuditService::STRIPE_MODE_CHANGED, ['from' => $from, 'to' => $target, 'detachedReaders' => $detached,
            'account' => $target === StripeAccountResolver::LIVE ? ['id' => $this->accounts->isInternal() ? 'plateforme' : StripeAccountResolver::mask($this->accounts->accountId(StripeAccountResolver::LIVE)), 'chargesEnabled' => true, 'payoutsEnabled' => true] : null],
            null, null, null, null, $user);

        return ['mode' => $target, 'changed' => true, 'detachedReaders' => $detached];
    }

    private function pendingCount(): int
    {
        $em = $this->emProvider->getEntityManager();

        return (int) $em->getRepository(PosTerminalPayment::class)->createQueryBuilder('p')->select('COUNT(p.id)')
            ->where('p.status IN (:pending) OR p.refundStatus = :waiting')->setParameter('pending', PosTerminalPayment::PENDING)->setParameter('waiting', PosTerminalPayment::REFUND_WAITING)
            ->getQuery()->getSingleScalarResult();
    }
}

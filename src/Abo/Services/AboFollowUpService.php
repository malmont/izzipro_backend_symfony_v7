<?php

namespace App\Abo\Services;

use App\Entity\Order;
use App\Entity\Subscription;
use App\Entity\SubscriptionPlan;
use App\Services\CaisseService\StoreClock;
use App\Services\TenantEntityManagerProvider;

/**
 * Lectures de suivi des abonnements (lot ABO-0, 18/11/2026, `docs/plan-abo-universel.md`), pour la gestion web ET l'application de
 * gestion mobile, par les mêmes routes `/api/site/abonnements/...` (ROLE_ADMIN, module Boutique) : les formules et leurs compteurs, les
 * abonnés d'une formule, la fiche d'un abonné. Lecture seule. `$withIdentity` faux (assistant du site) : aucune donnée personnelle.
 * Dates en ATOM à l'heure du magasin.
 */
class AboFollowUpService
{
    public const PAGE_DEFAULT = 25;
    public const PAGE_MAX = 100;

    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly BenefitService $benefits, private readonly StoreClock $clock)
    {
    }

    /** @return list<array<string, mixed>> les formules du site (actives et inactives), leurs prestations et leurs compteurs d'abonnés */
    public function plans(string $locale): array
    {
        $em = $this->emProvider->getEntityManager();
        $plans = $em->getRepository(SubscriptionPlan::class)->findBy([], ['active' => 'DESC', 'id' => 'ASC']);
        $counts = [];
        foreach ($em->getConnection()->fetchAllAssociative('SELECT plan_id, status, COUNT(*) AS n FROM subscription GROUP BY plan_id, status') as $row) {
            $counts[(int) $row['plan_id']][(string) $row['status']] = (int) $row['n'];
        }
        $byPlan = $this->benefits->planBenefits()->forPlans(array_map(fn (SubscriptionPlan $p) => (int) $p->getId(), $plans));
        $out = [];
        foreach ($plans as $plan) {
            $c = array_fill_keys(Subscription::STATUSES, 0);
            foreach ($counts[(int) $plan->getId()] ?? [] as $status => $n) {
                if (isset($c[$status])) {
                    $c[$status] = $n;
                }
            }
            $out[] = $this->planHead($plan, $locale) + [
                'benefits' => array_map(fn ($pb) => $this->benefits->view($pb, $locale), $byPlan[(int) $plan->getId()] ?? []),
                'counts' => $c, 'toFollow' => $c[Subscription::STATUS_PAST_DUE] + $c[Subscription::STATUS_PAUSED],
            ];
        }

        return $out;
    }

    /**
     * Les abonnés d'une formule, du plus récent au plus ancien.
     *
     * @return array{plan: array<string, mixed>, subscriptions: list<array<string, mixed>>, total: int, page: int, limit: int}
     * @throws AboException 404 PLAN_NOT_FOUND, 422 STATUS_INVALID
     */
    public function subscribers(int $planId, ?string $status, int $page, int $limit, string $locale, bool $withIdentity = true): array
    {
        $em = $this->emProvider->getEntityManager();
        $plan = $em->getRepository(SubscriptionPlan::class)->find($planId);
        if ($plan === null) {
            throw new AboException(404, 'PLAN_NOT_FOUND', 'Formule introuvable.');
        }
        if ($status !== null && $status !== '' && !in_array($status, Subscription::STATUSES, true)) {
            throw new AboException(422, 'STATUS_INVALID', 'Statut inconnu : ' . implode(', ', Subscription::STATUSES) . '.', 'status');
        }
        $page = max(1, $page);
        $limit = max(1, min(self::PAGE_MAX, $limit > 0 ? $limit : self::PAGE_DEFAULT));
        $qb = $em->getRepository(Subscription::class)->createQueryBuilder('s')->where('s.plan = :plan')->setParameter('plan', $plan);
        if ($status !== null && $status !== '') {
            $qb->andWhere('s.status = :status')->setParameter('status', $status);
        }
        $total = (int) (clone $qb)->select('COUNT(s.id)')->getQuery()->getSingleScalarResult();
        $rows = $qb->leftJoin('s.user', 'u')->addSelect('u')->orderBy('s.createdAt', 'DESC')->addOrderBy('s.id', 'DESC')
            ->setFirstResult(($page - 1) * $limit)->setMaxResults($limit)->getQuery()->getResult();

        return ['plan' => $this->planHead($plan, $locale), 'subscriptions' => array_map(fn (Subscription $s) => $this->row($s, $withIdentity), $rows), 'total' => $total, 'page' => $page, 'limit' => $limit];
    }

    /**
     * La fiche d'un abonné : `todo`, `history` et `balance` présents et vides jusqu'au lot ABO-1 (forme stable).
     *
     * @return array<string, mixed>
     * @throws AboException 404 SUBSCRIPTION_NOT_FOUND
     */
    public function subscription(int $id, string $locale, bool $withIdentity = true): array
    {
        $em = $this->emProvider->getEntityManager();
        $s = $em->getRepository(Subscription::class)->find($id);
        if ($s === null || $s->getPlan() === null) {
            throw new AboException(404, 'SUBSCRIPTION_NOT_FOUND', 'Abonnement introuvable.');
        }
        // commandes d'échéance : même forme que la fiche d'abonnement du client (SubscriptionOutputDto), total en centimes
        $orders = array_map(fn (Order $o) => ['id' => (int) $o->getId(), 'reference' => (string) $o->getReference(), 'date' => $o->getOrderDate()?->format(\DateTimeInterface::ATOM), 'total' => (int) round((float) $o->getTotalAmount())],
            $em->getRepository(Order::class)->findBy(['subscription' => $s], ['id' => 'DESC'], 24));

        return $this->row($s, $withIdentity) + [
            'plan' => $this->planHead($s->getPlan(), $locale), 'benefits' => $this->benefits->viewsFor($s->getPlan(), $locale),
            'canceledAt' => $this->at($s->getCanceledAt()), 'fulfillmentTarget' => $s->getFulfillmentTarget(), 'orders' => $orders,
            'todo' => [], 'history' => [], 'balance' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function planHead(SubscriptionPlan $plan, string $locale): array
    {
        return ['id' => (int) $plan->getId(), 'name' => $plan->getName($locale), 'price' => $plan->getPrice(), 'currency' => $plan->getCurrency(), 'interval' => $plan->getInterval(),
            'intervalCount' => $plan->getIntervalCount(), 'active' => $plan->isActive(), 'fulfillmentType' => $plan->getFulfillmentType()];
    }

    /** @return array<string, mixed> une ligne d'abonné ; sans identité pour l'assistant */
    private function row(Subscription $s, bool $withIdentity): array
    {
        $issue = $s->getPaymentIssue();
        $row = [
            'subscriptionId' => (int) $s->getId(), 'status' => $s->getStatus(), 'quantity' => $s->getQuantity(), 'currentPeriodEnd' => $this->at($s->getCurrentPeriodEnd()),
            'cancelAtPeriodEnd' => $s->isCancelAtPeriodEnd(), 'pausedAt' => $this->at($s->getPausedAt()), 'createdAt' => $this->at($s->getCreatedAt()),
            // l'impayé : montant et essai, jamais le lien de paiement (il ouvre la facture du client)
            'paymentIssue' => $issue !== null ? ['amountDue' => (int) ($issue['amountDue'] ?? 0), 'currency' => (string) ($issue['currency'] ?? ''), 'attemptCount' => (int) ($issue['attemptCount'] ?? 0),
                'maxAttempts' => (int) ($issue['maxAttempts'] ?? 0), 'nextPaymentAttempt' => $issue['nextPaymentAttempt'] ?? null] : null,
        ];
        if ($withIdentity) {
            $u = $s->getUser();
            $row['customer'] = $u !== null ? ['id' => (int) $u->getId(), 'firstname' => $u->getFirstname(), 'lastname' => $u->getLastname(), 'email' => $u->getEmail()] : null;
        }

        return $row;
    }

    private function at(?\DateTimeInterface $at): ?string
    {
        return $at === null ? null : \DateTimeImmutable::createFromInterface($at)->setTimezone($this->clock->timezone())->format(\DATE_ATOM);
    }
}

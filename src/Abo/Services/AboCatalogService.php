<?php

namespace App\Abo\Services;

use App\Entity\Product;
use App\Entity\Subscription;
use App\Entity\SubscriptionPlan;
use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use App\Services\TenantEntityManagerProvider;

/**
 * Formules d'abonnement du commerçant (lot ABO-CONF, 19/11/2026, contrat validé par le chef) : UN service pour EasyAdmin, les routes de la
 * gestion web et l'assistant du site ; mêmes contrôles, mêmes codes.
 *
 * - Devise : celle du site, imposée à la création par la gestion web et l'assistant.
 * - Formule « branchée » (offre de la plateforme, webhook) : EasyAdmin seulement (403 PLAN_PLATFORM_ONLY ailleurs).
 * - Prix ou rythme modifiés : la ligne en cours de l'historique (`subscription_plan_price`) est close, une autre s'ouvre, et le prix Stripe
 *   de la formule repart à zéro : les NOUVEAUX abonnés paient le nouveau prix (un nouveau prix Stripe naît à la prochaine souscription, sur
 *   le même produit Stripe) ; les abonnés en cours gardent le leur. Corrige le défaut d'EasyAdmin (un prix modifié gardait l'ancien prix
 *   Stripe, même pour les nouveaux abonnés).
 * - Une formule qui a eu un abonné ne se supprime pas (409 PLAN_HAS_SUBSCRIBERS) : elle s'archive (plus vendue, les abonnés continuent).
 */
class AboCatalogService
{
    public const MAX_PRICE = 100_000_000; // 1 000 000,00
    public const MAX_TRIAL_DAYS = 365;
    public const MAX_TERMS = 120;
    public const MAX_INTERVAL_COUNT = 12;
    /** Champs qu'une formule accepte hors EasyAdmin (jamais les champs « branchés ») */
    public const FIELDS = ['productId', 'names', 'descriptions', 'features', 'badges', 'price', 'interval', 'intervalCount', 'trialDays', 'minimumTerms',
        'maxPauseDays', 'pausesPerYear', 'singlePeriod', 'highlighted', 'active'];

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly BenefitService $benefits,
        private readonly TenantCurrencyProvider $currency,
        private readonly ?\App\Services\ContentAuditService\ContentAuditRecorder $audit = null,
        private readonly ?\App\Services\StripeService\StripeService $stripe = null,
    ) {
    }

    /** @throws AboException 404 PLAN_NOT_FOUND */
    public function plan(int $id): SubscriptionPlan
    {
        return $this->emProvider->getEntityManager()->getRepository(SubscriptionPlan::class)->find($id)
            ?? throw new AboException(404, 'PLAN_NOT_FOUND', 'Formule introuvable.');
    }

    /**
     * Crée ou modifie une formule par la gestion web ou l'assistant (champs de `FIELDS` seulement).
     *
     * @param array<string, mixed> $data
     * @throws AboException
     */
    public function savePlan(?SubscriptionPlan $plan, array $data, ?string $by): SubscriptionPlan
    {
        if ($plan !== null && $plan->hasFulfillment()) {
            throw new AboException(403, 'PLAN_PLATFORM_ONLY', 'Cette formule est branchée sur un service de la plateforme : elle se règle dans EasyAdmin.');
        }
        $em = $this->emProvider->getEntityManager();
        $creating = $plan === null;
        $before = $creating ? null : $this->priceState($plan);
        $old = $creating ? null : $this->auditState($plan);
        $plan ??= (new SubscriptionPlan())->setCurrency($this->currency->code())->setActive(true);
        $this->apply($plan, $data, $creating);
        $this->validatePlan($plan);
        $em->wrapInTransaction(function () use ($em, $plan, $before, $by, $creating) {
            if ($creating) {
                $em->persist($plan);
            }
            $em->flush();
            $this->afterSave($plan, $before, $by);
            $em->flush();
        });
        $em->getRepository(SubscriptionPlan::class)->keepOnlyHighlighted($plan);
        $this->audit?->record('subscription_plan', (int) $plan->getId(), $creating ? 'create' : 'update', $old, $this->auditState($plan));

        return $plan;
    }

    /**
     * Le même contrôle et le même historique des prix pour EasyAdmin : appelé avant l'écriture (`$before` : l'état d'avant, null à la
     * création), puis `afterSave` après l'écriture.
     *
     * @throws AboException
     */
    public function validatePlan(SubscriptionPlan $plan): void
    {
        $name = trim($plan->getName('fr'));
        if ($name === '') {
            throw new AboException(422, 'PLAN_NAME_REQUIRED', 'Donnez un nom à la formule.', 'names');
        }
        if ($plan->getProduct() === null || !$plan->getProduct()->isSubscriptionEnabled()) {
            throw new AboException(422, 'PRODUCT_NOT_SUBSCRIBABLE', 'Choisissez un produit porteur dont l\'abonnement est activé (Produits, « Modes de vente »).', 'productId');
        }
        if ($plan->getPrice() < 0 || $plan->getPrice() > self::MAX_PRICE) {
            throw new AboException(422, 'PLAN_PRICE_INVALID', 'Prix en centimes, de 0 à 1 000 000,00.', 'price');
        }
        if (!in_array($plan->getInterval(), SubscriptionPlan::INTERVALS, true) || $plan->getIntervalCount() < 1 || $plan->getIntervalCount() > self::MAX_INTERVAL_COUNT) {
            throw new AboException(422, 'PLAN_INTERVAL_INVALID', 'Rythme : semaine, mois ou année, tous les 1 à 12.', 'interval');
        }
        if ($plan->getTrialDays() < 0 || $plan->getTrialDays() > self::MAX_TRIAL_DAYS) {
            throw new AboException(422, 'PLAN_TRIAL_INVALID', 'Essai gratuit : 0 à 365 jours.', 'trialDays');
        }
        if ($plan->getMinimumTerms() < 0 || $plan->getMinimumTerms() > self::MAX_TERMS || ($plan->isSinglePeriod() && $plan->getMinimumTerms() > 1)) {
            throw new AboException(422, 'PLAN_TERMS_INVALID', 'Engagement minimal : 0 à 120 périodes (une carte de séances n\'a pas d\'engagement).', 'minimumTerms');
        }
        if ($plan->getMaxPauseDays() < 1 || $plan->getMaxPauseDays() > 365 || $plan->getPausesPerYear() < 0 || $plan->getPausesPerYear() > 12) {
            throw new AboException(422, 'PLAN_PAUSE_INVALID', 'Pause : 1 à 365 jours, 0 à 12 fois par an.', 'maxPauseDays');
        }
    }

    /**
     * Après l'écriture : un prix, une devise ou un rythme changés ferment la ligne d'historique en cours et en ouvrent une ; le prix Stripe
     * repart à zéro (nouveaux abonnés au nouveau prix, abonnés en cours au leur). À la création : la première ligne.
     *
     * @param array{price: int, currency: string, interval: string, intervalCount: int, stripePriceId: ?string}|null $before
     */
    public function afterSave(SubscriptionPlan $plan, ?array $before, ?string $by): void
    {
        $conn = $this->emProvider->getEntityManager()->getConnection();
        $now = gmdate('Y-m-d H:i:s');
        $after = $this->priceState($plan);
        if ($before !== null && array_diff_key($before, ['stripePriceId' => 1]) === array_diff_key($after, ['stripePriceId' => 1])) {
            return; // rien de ce qui fait le prix n'a changé
        }
        if ($before !== null) {
            $open = $conn->fetchOne('SELECT id FROM subscription_plan_price WHERE plan_id = ? AND valid_until IS NULL ORDER BY id DESC LIMIT 1', [$plan->getId()]);
            if ($open !== false) {
                $conn->executeStatement('UPDATE subscription_plan_price SET valid_until = ?, stripe_price_id = COALESCE(stripe_price_id, ?) WHERE id = ?', [$now, $before['stripePriceId'], $open]);
            } else {
                // une formule d'avant le lot : sa première époque est écrite au moment où elle se clôt
                $conn->executeStatement('INSERT INTO subscription_plan_price (plan_id, price_cents, currency, interval, interval_count, stripe_price_id, valid_from, valid_until) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [$plan->getId(), $before['price'], $before['currency'], $before['interval'], $before['intervalCount'], $before['stripePriceId'], $plan->getCreatedAt()->format('Y-m-d H:i:s'), $now]);
            }
            $plan->setStripePriceId(null); // le prochain abonné fait naître le nouveau prix Stripe, sur le même produit
        }
        $conn->executeStatement('INSERT INTO subscription_plan_price (plan_id, price_cents, currency, interval, interval_count, valid_from, changed_by) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$plan->getId(), $plan->getPrice(), $plan->getCurrency(), $plan->getInterval(), $plan->getIntervalCount(), $now, $by !== null ? mb_substr($by, 0, 180) : null]);
    }

    /** @return array{price: int, currency: string, interval: string, intervalCount: int, stripePriceId: ?string} ce qui fait le prix */
    public function priceState(SubscriptionPlan $plan): array
    {
        return ['price' => $plan->getPrice(), 'currency' => $plan->getCurrency(), 'interval' => $plan->getInterval(), 'intervalCount' => $plan->getIntervalCount(),
            'stripePriceId' => $plan->getStripePriceId()];
    }

    /** @return list<array{price: int, currency: string, interval: string, intervalCount: int, from: string, until: ?string}> l'historique des prix, du plus récent au plus ancien */
    public function priceHistory(SubscriptionPlan $plan): array
    {
        return array_map(fn (array $r) => ['price' => (int) $r['price_cents'], 'currency' => (string) $r['currency'], 'interval' => (string) $r['interval'],
            'intervalCount' => (int) $r['interval_count'], 'from' => substr((string) $r['valid_from'], 0, 10), 'until' => $r['valid_until'] !== null ? substr((string) $r['valid_until'], 0, 10) : null],
            $this->emProvider->getEntityManager()->getConnection()->fetchAllAssociative('SELECT * FROM subscription_plan_price WHERE plan_id = ? ORDER BY valid_from DESC, id DESC', [$plan->getId()]));
    }

    /** @throws AboException 403 PLAN_PLATFORM_ONLY */
    public function archive(SubscriptionPlan $plan, bool $archived): SubscriptionPlan
    {
        if ($plan->hasFulfillment()) {
            throw new AboException(403, 'PLAN_PLATFORM_ONLY', 'Cette formule est branchée sur un service de la plateforme : elle se règle dans EasyAdmin.');
        }
        $plan->setActive(!$archived);
        $this->emProvider->getEntityManager()->flush();
        $this->audit?->record('subscription_plan', (int) $plan->getId(), $archived ? 'archive' : 'reactivate', null, ['active' => $plan->isActive()]);

        return $plan;
    }

    /** Une formule qui a eu un abonné ne se supprime pas : elle s'archive. @throws AboException 409 PLAN_HAS_SUBSCRIBERS, 403 PLAN_PLATFORM_ONLY */
    public function assertDeletable(SubscriptionPlan $plan): void
    {
        if ($plan->getId() !== null && $this->emProvider->getEntityManager()->getConnection()->fetchOne('SELECT 1 FROM subscription WHERE plan_id = ? LIMIT 1', [$plan->getId()]) !== false) {
            throw new AboException(409, 'PLAN_HAS_SUBSCRIBERS', 'Cette formule a eu des abonnés : archivez-la (elle n\'est plus vendue, les abonnés continuent).');
        }
    }

    public function delete(SubscriptionPlan $plan): void
    {
        if ($plan->hasFulfillment()) {
            throw new AboException(403, 'PLAN_PLATFORM_ONLY', 'Cette formule est branchée sur un service de la plateforme : elle se règle dans EasyAdmin.');
        }
        $this->assertDeletable($plan);
        $em = $this->emProvider->getEntityManager();
        $id = (int) $plan->getId();
        $old = $this->auditState($plan);
        $em->remove($plan);
        $em->flush();
        $this->audit?->record('subscription_plan', $id, 'delete', $old, null);
    }

    /**
     * La liste COMPLÈTE des prestations d'une formule (`PUT …/formules/{id}/prestations`) : ajoutées ou réglées, et retirées si absentes ;
     * une prestation retirée garde les droits déjà nés au solde (ABO-1).
     *
     * @param list<array<string, mixed>> $rows
     * @throws AboException
     */
    public function setBenefits(SubscriptionPlan $plan, array $rows): int
    {
        if ($plan->hasFulfillment()) {
            throw new AboException(403, 'PLAN_PLATFORM_ONLY', 'Cette formule est branchée sur un service de la plateforme : elle se règle dans EasyAdmin.');
        }
        $em = $this->emProvider->getEntityManager();
        $keep = [];
        $em->wrapInTransaction(function () use ($em, $plan, $rows, &$keep) {
            foreach ($rows as $i => $row) {
                $benefit = is_array($row) ? $this->benefits->benefits()->find((int) ($row['benefitId'] ?? 0)) : null;
                if ($benefit === null) {
                    throw new AboException(404, 'BENEFIT_NOT_FOUND', sprintf('Prestation n° %d introuvable.', (int) (is_array($row) ? ($row['benefitId'] ?? 0) : 0)), "benefits[$i].benefitId");
                }
                $keep[] = (int) $this->benefits->setPlanBenefit($plan, $benefit, $row, false)->getBenefit()->getId();
            }
            $em->flush();
            foreach ($this->benefits->planBenefits()->forPlan($plan) as $pb) {
                if (!in_array((int) $pb->getBenefit()?->getId(), $keep, true)) {
                    $em->remove($pb); // les droits déjà nés gardent leur prestation (benefit_id) : ils restent au solde
                }
            }
            $em->flush();
        });

        return count($keep);
    }

    /**
     * Ce que la formule sera, SANS rien écrire : la vue et la phrase « ce que verra l'abonné ».
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     * @throws AboException
     */
    public function preview(?SubscriptionPlan $plan, array $data, string $locale): array
    {
        $probe = $plan !== null ? clone $plan : (new SubscriptionPlan())->setCurrency($this->currency->code())->setActive(true);
        $this->apply($probe, $data, $plan === null);
        $this->validatePlan($probe);
        $priceChange = $plan !== null && array_diff_key($this->priceState($plan), ['stripePriceId' => 1]) !== array_diff_key($this->priceState($probe), ['stripePriceId' => 1]);

        return ['plan' => $this->head($probe, $locale), 'summary' => $this->summary($probe, $locale),
            'priceChange' => $priceChange ? ($locale === 'en' ? 'New price for new subscribers only: current subscribers keep theirs.' : 'Nouveau prix pour les nouveaux abonnés seulement : les abonnés en cours gardent le leur.') : null,
            'warnings' => $this->warnings()];
    }

    /** @return array<string, mixed> la fiche d'une formule pour la gestion web : tête, prestations, historique des prix, phrase, avertissements */
    public function view(SubscriptionPlan $plan, string $locale): array
    {
        return ['plan' => $this->head($plan, $locale), 'benefits' => $this->benefits->viewsFor($plan, $locale), 'priceHistory' => $this->priceHistory($plan),
            'summary' => $this->summary($plan, $locale), 'hasSubscribers' => $this->emProvider->getEntityManager()->getConnection()->fetchOne('SELECT 1 FROM subscription WHERE plan_id = ? LIMIT 1', [$plan->getId()]) !== false,
            'warnings' => $this->warnings()];
    }

    /**
     * « Ce que verra l'abonné », par le serveur (fr ou en) : prix et rythme, essai, engagement, carte, puis les prestations. Exemple :
     * « 49,00 $ par mois. Essai gratuit de 14 jours. Engagement de 3 mois. 4 séances Yoga par mois ; … »
     */
    public function summary(SubscriptionPlan $plan, string $locale): string
    {
        $en = str_starts_with($locale, 'en');
        $amount = self::money($plan->getPrice(), $plan->getCurrency(), $en);
        $unit = ['week' => $en ? 'week' : 'semaine', 'year' => $en ? 'year' : 'an'][$plan->getInterval()] ?? ($en ? 'month' : 'mois');
        $n = $plan->getIntervalCount();
        $every = $n <= 1 ? ($en ? "per $unit" : "par $unit") : ($en ? "every $n {$unit}s" : sprintf('tous les %d %s', $n, $unit === 'mois' ? 'mois' : ($unit === 'semaine' ? 'semaines' : 'ans')));
        $parts = [$plan->isSinglePeriod()
            ? ($en ? sprintf('%s, paid once, valid %d %s%s, no renewal.', $amount, $n, $unit, $n > 1 ? 's' : '') : sprintf('%s, payé une fois, valable %d %s, sans renouvellement.', $amount, $n, $unit === 'semaine' && $n > 1 ? 'semaines' : ($unit === 'an' && $n > 1 ? 'ans' : $unit)))
            : sprintf('%s %s.', $amount, $every)];
        if ($plan->getTrialDays() > 0) {
            $parts[] = $en ? sprintf('Free trial of %d days.', $plan->getTrialDays()) : sprintf('Essai gratuit de %d jours.', $plan->getTrialDays());
        }
        if ($plan->getMinimumTerms() > 1) {
            $parts[] = $en ? sprintf('Minimum commitment: %d periods.', $plan->getMinimumTerms()) : sprintf('Engagement de %d périodes.', $plan->getMinimumTerms());
        }
        foreach ($plan->getId() !== null ? $this->benefits->planBenefits()->forPlan($plan) : [] as $pb) {
            $parts[] = $this->benefits->summary($pb, $locale);
        }

        return implode(' ', $parts);
    }

    /**
     * Avertissements de la gestion web et d'EasyAdmin à la création (décision du propriétaire, 19/11/2026) : un site extérieur sans compte
     * Stripe connecté actif ne pourra pas vendre en réel.
     *
     * @return list<array{code: string, text: string}>
     */
    public function warnings(): array
    {
        if ($this->stripe === null || $this->stripe->accountOptions() !== null) {
            return [];
        }

        return [['code' => 'STRIPE_ACCOUNT_REQUIRED', 'text' => 'Un compte Stripe connecté est nécessaire pour vendre cette formule en réel (Paiements, « Connecter Stripe »).']];
    }

    /** @return array<string, mixed> */
    private function head(SubscriptionPlan $plan, string $locale): array
    {
        return ['id' => $plan->getId(), 'productId' => $plan->getProduct()?->getId(), 'names' => $plan->getNames(), 'name' => $plan->getName($locale), 'descriptions' => $plan->getDescriptions(),
            'features' => $plan->getFeatures(), 'badges' => $plan->getBadges(), 'price' => $plan->getPrice(), 'currency' => $plan->getCurrency(), 'interval' => $plan->getInterval(),
            'intervalCount' => $plan->getIntervalCount(), 'trialDays' => $plan->getTrialDays(), 'minimumTerms' => $plan->getMinimumTerms(), 'maxPauseDays' => $plan->getMaxPauseDays(),
            'pausesPerYear' => $plan->getPausesPerYear(), 'singlePeriod' => $plan->isSinglePeriod(), 'highlighted' => $plan->isHighlighted(), 'active' => $plan->isActive()];
    }

    /**
     * Pose les champs donnés (et eux seuls) ; un champ inconnu ou « branché » est refusé.
     *
     * @param array<string, mixed> $data
     * @throws AboException 422
     */
    private function apply(SubscriptionPlan $plan, array $data, bool $creating): void
    {
        foreach (array_keys($data) as $key) {
            if (!in_array($key, self::FIELDS, true)) {
                throw new AboException(422, str_starts_with((string) $key, 'fulfillment') ? 'PLAN_PLATFORM_ONLY' : 'FIELD_UNKNOWN', sprintf('Champ « %s » non modifiable ici.', $key), (string) $key);
            }
        }
        $int = static fn (string $k, int $default): int => array_key_exists($k, $data) ? (is_int($data[$k]) ? $data[$k] : (is_string($data[$k]) && preg_match('/^-?\d+$/', $data[$k]) ? (int) $data[$k] : PHP_INT_MIN)) : $default;
        $texts = static fn (mixed $v): ?array => is_array($v) ? array_map('strval', array_filter($v, 'is_scalar')) : null;
        if (array_key_exists('productId', $data)) {
            $product = $this->emProvider->getEntityManager()->getRepository(Product::class)->find((int) $data['productId']);
            if ($product === null) {
                throw new AboException(422, 'PRODUCT_NOT_SUBSCRIBABLE', 'Produit porteur introuvable.', 'productId');
            }
            $plan->setProduct($product);
        }
        if (array_key_exists('names', $data)) {
            $plan->setNames($texts($data['names']) ?? []);
        }
        if (array_key_exists('descriptions', $data)) {
            $plan->setDescriptions($texts($data['descriptions']));
        }
        if (array_key_exists('features', $data)) {
            $plan->setFeatures(is_array($data['features']) ? $data['features'] : null);
        }
        if (array_key_exists('badges', $data)) {
            $plan->setBadges($texts($data['badges']));
        }
        foreach (['price' => fn (int $v) => $plan->setPrice($v), 'trialDays' => fn (int $v) => $plan->setTrialDays($v), 'minimumTerms' => fn (int $v) => $plan->setMinimumTerms($v)] as $key => $set) {
            if (array_key_exists($key, $data)) {
                $v = $int($key, 0);
                if ($v === PHP_INT_MIN || $v < 0) {
                    throw new AboException(422, ['price' => 'PLAN_PRICE_INVALID', 'trialDays' => 'PLAN_TRIAL_INVALID', 'minimumTerms' => 'PLAN_TERMS_INVALID'][$key], 'Nombre entier positif attendu.', $key);
                }
                $set($v);
            }
        }
        if (array_key_exists('interval', $data)) {
            $plan->setInterval((string) $data['interval']);
        }
        if (array_key_exists('intervalCount', $data)) {
            $v = $int('intervalCount', 1);
            if ($v === PHP_INT_MIN || $v < 1) {
                throw new AboException(422, 'PLAN_INTERVAL_INVALID', 'Rythme : tous les 1 à 12.', 'intervalCount');
            }
            $plan->setIntervalCount($v);
        }
        foreach (['maxPauseDays' => [1, 365], 'pausesPerYear' => [0, 12]] as $key => [$min, $max]) {
            if (array_key_exists($key, $data)) {
                $v = $int($key, $min);
                if ($v === PHP_INT_MIN || $v < $min || $v > $max) {
                    throw new AboException(422, 'PLAN_PAUSE_INVALID', sprintf('%s : de %d à %d.', $key, $min, $max), $key);
                }
                $key === 'maxPauseDays' ? $plan->setMaxPauseDays($v) : $plan->setPausesPerYear($v);
            }
        }
        foreach (['singlePeriod' => fn (bool $v) => $plan->setSinglePeriod($v), 'highlighted' => fn (bool $v) => $plan->setHighlighted($v), 'active' => fn (bool $v) => $plan->setActive($v)] as $key => $set) {
            if (array_key_exists($key, $data)) {
                if (!is_bool($data[$key])) {
                    throw new AboException(422, 'MUST_BE_BOOLEAN', sprintf('%s : vrai ou faux.', $key), $key);
                }
                $set($data[$key]);
            }
        }
        if ($creating && !array_key_exists('maxPauseDays', $data)) {
            $plan->setMaxPauseDays(90);
        }
    }

    /** @return array<string, mixed> l'état écrit au journal du site */
    private function auditState(SubscriptionPlan $plan): array
    {
        return ['name' => $plan->getName('fr'), 'price' => $plan->getPrice(), 'currency' => $plan->getCurrency(), 'interval' => $plan->getInterval(), 'intervalCount' => $plan->getIntervalCount(),
            'trialDays' => $plan->getTrialDays(), 'minimumTerms' => $plan->getMinimumTerms(), 'singlePeriod' => $plan->isSinglePeriod(), 'active' => $plan->isActive()];
    }

    private static function money(int $cents, string $currency, bool $en): string
    {
        $value = number_format($cents / 100, 2, $en ? '.' : ',', $en ? ',' : "\u{202F}");
        $symbol = in_array($currency, ['CAD', 'USD'], true) ? '$' : ($currency === 'EUR' ? '€' : $currency);

        return $en && $symbol === '$' ? '$' . $value : $value . "\u{00A0}" . $symbol;
    }
}

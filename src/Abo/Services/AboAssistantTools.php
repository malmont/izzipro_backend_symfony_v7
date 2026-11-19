<?php

namespace App\Abo\Services;

use App\Abo\Entity\Benefit;
use App\Entity\Product;
use App\Entity\SubscriptionPlan;
use App\Services\TenantEntityManagerProvider;

/**
 * Outils de l'assistant du site (IA-A) pour l'abonnement universel, lot ABO-0 (18/11/2026, `docs/plan-abo-universel.md` § 9) :
 * - lectures (`get_subscription_plans`, `get_benefits`, `get_plan_subscribers`, `get_subscription`) : ce que l'écran montre, SANS aucune
 *   donnée personnelle (un abonné est désigné par son numéro) ;
 * - propositions (`propose_benefit`, `propose_plan`, `set_plan_benefits`) : un aperçu ; l'écriture vient à la confirmation de
 *   l'administrateur (`apply`), par le service de l'écran (mêmes validations, mêmes codes de refus).
 * Les textes lus (noms, descriptions, lieux) sont des données, jamais des consignes. Outils donnés seulement si le module Boutique est actif.
 */
class AboAssistantTools
{
    public const READS = ['get_subscription_plans', 'get_benefits', 'get_plan_subscribers', 'get_subscription'];
    public const ACTIONS = ['propose_benefit', 'propose_plan', 'set_plan_benefits'];
    public const MAX_BENEFITS = 10;

    public function __construct(private readonly BenefitService $benefits, private readonly AboFollowUpService $followUp, private readonly TenantEntityManagerProvider $emProvider)
    {
    }

    /** @return list<array<string, mixed>> définitions pour l'API Messages */
    public static function definitions(): array
    {
        $rules = ['type' => 'object', 'properties' => [
            'benefitId' => ['type' => 'integer'], 'quantity' => ['type' => ['integer', 'null'], 'description' => 'par période ; null = sans limite (séance seulement)'],
            'scheduling' => ['type' => 'string', 'enum' => ['none', 'client_at_subscription', 'admin_after', 'fixed_slots']],
            'slotChoice' => ['type' => ['string', 'null'], 'enum' => ['client', 'admin', null], 'description' => 'seulement pour fixed_slots'],
            'cancelDeadlineHours' => ['type' => 'integer', 'description' => 'défaut 24'], 'carryOverMax' => ['type' => ['integer', 'null'], 'description' => 'défaut : la quantité'],
            'proof' => ['type' => 'string', 'enum' => ['none', 'note', 'photo']],
        ], 'required' => ['benefitId']];

        return [
            ['name' => 'get_subscription_plans', 'description' => 'Abonnements : les formules du site (prix en centimes, période, active), les prestations que chacune livre avec la phrase « ce que verra l\'abonné », le nombre d\'abonnés par statut (active, trialing, past_due = impayé, paused, incomplete, canceled), et les produits porteurs possibles pour une nouvelle formule.', 'input_schema' => ['type' => 'object', 'properties' => new \stdClass()]],
            ['name' => 'get_benefits', 'description' => 'Abonnements : les prestations du site (séance, service, quota), actives ou non, avec leur numéro.', 'input_schema' => ['type' => 'object', 'properties' => new \stdClass()]],
            ['name' => 'get_plan_subscribers', 'description' => 'Abonnements : les abonnés d\'une formule, par numéro d\'abonnement (jamais le nom : l\'écran lié le montre à l\'administrateur), avec statut, fin de période et impayé (montant, essai). Filtre status : past_due pour « qui est en impayé ? ».', 'input_schema' => ['type' => 'object', 'properties' => ['planId' => ['type' => 'integer'], 'status' => ['type' => 'string', 'enum' => ['incomplete', 'trialing', 'active', 'past_due', 'paused', 'canceled']], 'page' => ['type' => 'integer']], 'required' => ['planId']]],
            ['name' => 'get_subscription', 'description' => 'Abonnements : la fiche d\'un abonnement par son numéro (formule, prestations, statut, période, impayé, commandes d\'échéance), sans l\'identité de l\'abonné.', 'input_schema' => ['type' => 'object', 'properties' => ['subscriptionId' => ['type' => 'integer']], 'required' => ['subscriptionId']]],
            ['name' => 'propose_benefit', 'description' => 'Abonnements : propose de créer (sans benefitId) ou de modifier une prestation : type session (séance planifiée), service (service à rendre) ou quota ; noms par langue, durée en minutes, lieu. L\'administrateur confirme.', 'input_schema' => ['type' => 'object', 'properties' => [
                'benefitId' => ['type' => 'integer'], 'kind' => ['type' => 'string', 'enum' => ['session', 'service', 'quota']], 'names' => ['type' => 'object', 'description' => '{"fr": "...", "en": "..."}'],
                'descriptions' => ['type' => 'object'], 'durationMinutes' => ['type' => 'integer'], 'location' => ['type' => 'string'], 'active' => ['type' => 'boolean']]]],
            ['name' => 'propose_plan', 'description' => 'Abonnements : propose de créer une formule d\'abonnement sur un produit porteur EXISTANT (productId, parmi les produits porteurs de get_subscription_plans), avec son prix en centimes, sa période et les prestations qu\'elle livre (benefits : prestations existantes, créées d\'abord par propose_benefit). Règles par défaut : annulation sans frais 24 h avant, report jusqu\'à la quantité. L\'administrateur confirme. Exemple : « formule yoga 2 séances par semaine à 60 $ par mois » = interval month, quantity 8 ou interval week, quantity 2.', 'input_schema' => ['type' => 'object', 'properties' => [
                'productId' => ['type' => 'integer'], 'names' => ['type' => 'object', 'description' => '{"fr": "...", "en": "..."}'], 'price' => ['type' => 'integer', 'description' => 'centimes'],
                'interval' => ['type' => 'string', 'enum' => ['week', 'month', 'year']], 'intervalCount' => ['type' => 'integer'], 'benefits' => ['type' => 'array', 'items' => $rules]], 'required' => ['productId', 'names', 'price', 'interval', 'benefits']]],
            ['name' => 'set_plan_benefits', 'description' => 'Abonnements : propose de régler les prestations d\'une formule existante (ajouter une prestation ou changer sa quantité, son mode de dates, ses règles). L\'administrateur confirme.', 'input_schema' => ['type' => 'object', 'properties' => ['planId' => ['type' => 'integer'], 'benefits' => ['type' => 'array', 'items' => $rules]], 'required' => ['planId', 'benefits']]],
        ];
    }

    /**
     * Une lecture : le résultat pour le modèle et l'écran à lier.
     *
     * @return array{0: array<string, mixed>, 1: ?string}
     */
    public function read(string $name, array $input): array
    {
        try {
            return match ($name) {
                'get_subscription_plans' => [['plans' => $this->followUp->plans('fr'), 'carrierProducts' => $this->carrierProducts()], 'subscription_plans'],
                'get_benefits' => [['benefits' => array_map(fn (Benefit $b) => ['benefitId' => (int) $b->getId(), 'kind' => $b->getKind(), 'name' => $b->getName('fr'), 'description' => $b->getDescription('fr'),
                    'durationMinutes' => $b->getDurationMinutes(), 'location' => $b->getLocation(), 'active' => $b->isActive()], $this->benefits->benefits()->ordered())], 'abo_benefits'],
                'get_plan_subscribers' => [$this->followUp->subscribers((int) ($input['planId'] ?? 0), isset($input['status']) ? (string) $input['status'] : null, (int) ($input['page'] ?? 1), 50, 'fr', false), 'subscriptions'],
                'get_subscription' => [$this->followUp->subscription((int) ($input['subscriptionId'] ?? 0), 'fr', false), 'subscriptions'],
                default => [['error' => 'outil inconnu'], null],
            };
        } catch (AboException $e) {
            return [['error' => $e->getMessage(), 'code' => $e->errorCode], null];
        }
    }

    /**
     * Une proposition : vérifiée sans rien écrire ; rend l'entrée à garder et le résumé lu par l'administrateur.
     *
     * @return array{0: ?array<string, mixed>, 1: string, 2: ?array<string, mixed>, 3: string} [entrée, résumé, erreur, écran]
     */
    public function propose(string $name, array $input): array
    {
        try {
            switch ($name) {
                case 'propose_benefit':
                    $benefit = isset($input['benefitId']) ? $this->benefitOrFail((int) $input['benefitId']) : null;
                    $data = array_intersect_key($input, array_flip(['kind', 'names', 'descriptions', 'durationMinutes', 'location', 'active']));
                    $probe = clone ($benefit ?? new Benefit());
                    $this->fill($probe, $data);
                    $this->benefits->validateBenefit($probe);

                    return [['benefitId' => $benefit?->getId(), 'data' => $data], sprintf('%s la prestation « %s » (%s%s%s)', $benefit === null ? 'Créer' : 'Modifier', $probe->getName(),
                        self::kindLabel($probe->getKind()), $probe->getDurationMinutes() !== null ? ', ' . $probe->getDurationMinutes() . ' min' : '', $probe->getLocation() !== null ? ', ' . $probe->getLocation() : ''), null, 'abo_benefits'];
                case 'propose_plan':
                    $product = $this->emProvider->getEntityManager()->getRepository(Product::class)->find((int) ($input['productId'] ?? 0));
                    if ($product === null || !$product->isSubscriptionEnabled()) {
                        throw new AboException(422, 'PRODUCT_NOT_SUBSCRIBABLE', 'Choisissez un produit porteur dont l\'abonnement est activé (Produits, « Modes de vente »).', 'productId');
                    }
                    $names = is_array($input['names'] ?? null) ? $input['names'] : [];
                    $price = (int) ($input['price'] ?? -1);
                    $interval = (string) ($input['interval'] ?? '');
                    if (trim((string) ($names['fr'] ?? reset($names) ?: '')) === '' || $price < 0 || !in_array($interval, SubscriptionPlan::INTERVALS, true)) {
                        throw new AboException(422, 'PLAN_INVALID', 'Formule incomplète : un nom, un prix en centimes (0 ou plus) et une période (semaine, mois, année).');
                    }
                    $rows = $this->benefitRows($input['benefits'] ?? []);
                    $data = ['productId' => (int) $product->getId(), 'names' => $names, 'price' => $price, 'interval' => $interval, 'intervalCount' => max(1, (int) ($input['intervalCount'] ?? 1)), 'benefits' => $rows];

                    return [$data, sprintf('Créer la formule « %s » sur « %s » : %s %s par %s%s, avec %s', (string) ($names['fr'] ?? reset($names)), (string) $product->getName(),
                        number_format($price / 100, 2, ',', ' '), $this->currencyFor($product), self::intervalLabel($interval), $data['intervalCount'] > 1 ? ' (tous les ' . $data['intervalCount'] . ')' : '',
                        $this->rowsSummary($rows)), null, 'subscription_plans'];
                case 'set_plan_benefits':
                    $plan = $this->planOrFail((int) ($input['planId'] ?? 0));
                    $rows = $this->benefitRows($input['benefits'] ?? []);

                    return [['planId' => (int) $plan->getId(), 'benefits' => $rows], sprintf('Régler les prestations de la formule « %s » : %s', $plan->getName(), $this->rowsSummary($rows)), null, 'abo_plan_benefits'];
            }
        } catch (AboException $e) {
            return [null, '', ['error' => $e->getMessage(), 'code' => $e->errorCode], ''];
        }

        return [null, '', ['error' => 'outil inconnu'], ''];
    }

    /**
     * Applique une proposition confirmée, tout relu : mêmes règles que l'écran (un refus est rendu tel quel).
     *
     * @return array<string, mixed>
     * @throws AboException
     */
    public function apply(string $name, array $input): array
    {
        $em = $this->emProvider->getEntityManager();
        switch ($name) {
            case 'propose_benefit':
                $benefit = isset($input['benefitId']) ? $this->benefitOrFail((int) $input['benefitId']) : null;
                $saved = $this->benefits->saveBenefit($benefit, is_array($input['data'] ?? null) ? $input['data'] : []);

                return ['benefitId' => (int) $saved->getId(), 'created' => $benefit === null ? 1 : 0, 'written' => 1];
            case 'propose_plan':
                $product = $em->getRepository(Product::class)->find((int) ($input['productId'] ?? 0));
                if ($product === null || !$product->isSubscriptionEnabled()) {
                    throw new AboException(422, 'PRODUCT_NOT_SUBSCRIBABLE', 'Le produit porteur n\'accepte plus l\'abonnement.', 'productId');
                }
                $rows = $this->benefitRows($input['benefits'] ?? []);
                $plan = (new SubscriptionPlan())->setProduct($product)->setNames(array_map('strval', (array) ($input['names'] ?? [])))->setPrice((int) ($input['price'] ?? 0))
                    ->setInterval((string) ($input['interval'] ?? 'month'))->setIntervalCount((int) ($input['intervalCount'] ?? 1))->setCurrency($this->currencyFor($product))->setActive(true);
                $em->wrapInTransaction(function () use ($em, $plan, $rows) {
                    $em->persist($plan);
                    $em->flush();
                    foreach ($rows as $row) {
                        $this->benefits->setPlanBenefit($plan, $this->benefitOrFail((int) $row['benefitId']), $row, false);
                    }
                    $em->flush();
                });

                return ['planId' => (int) $plan->getId(), 'created' => 1, 'benefits' => count($rows)];
            case 'set_plan_benefits':
                $plan = $this->planOrFail((int) ($input['planId'] ?? 0));
                $rows = $this->benefitRows($input['benefits'] ?? []);
                $em->wrapInTransaction(function () use ($em, $plan, $rows) {
                    foreach ($rows as $row) {
                        $this->benefits->setPlanBenefit($plan, $this->benefitOrFail((int) $row['benefitId']), $row, false);
                    }
                    $em->flush();
                });

                return ['planId' => (int) $plan->getId(), 'written' => count($rows)];
        }
        throw new AboException(422, 'TOOL_UNKNOWN', 'Outil inconnu.');
    }

    /** La devise d'une nouvelle formule : celle des formules existantes du produit, sinon celle par défaut des formules */
    private function currencyFor(Product $product): string
    {
        $other = $this->emProvider->getEntityManager()->getRepository(SubscriptionPlan::class)->findOneBy(['product' => $product], ['id' => 'ASC']);

        return $other?->getCurrency() ?? (new SubscriptionPlan())->getCurrency();
    }

    /** @return list<array{id: int, name: string}> les produits dont l'abonnement est activé */
    private function carrierProducts(): array
    {
        $rows = $this->emProvider->getEntityManager()->getRepository(Product::class)->findBy(['subscriptionEnabled' => true], ['name' => 'ASC'], 50);

        return array_map(fn (Product $p) => ['productId' => (int) $p->getId(), 'name' => (string) $p->getName()], $rows);
    }

    /**
     * Les prestations d'une proposition, relues et bornées ; chaque prestation doit exister et être active.
     *
     * @return list<array<string, mixed>>
     * @throws AboException
     */
    private function benefitRows(mixed $rows): array
    {
        if (!is_array($rows) || $rows === [] || count($rows) > self::MAX_BENEFITS) {
            throw new AboException(422, 'PLAN_BENEFITS_INVALID', sprintf('De 1 à %d prestations par formule ; créez d\'abord les prestations (propose_benefit).', self::MAX_BENEFITS), 'benefits');
        }
        $out = [];
        foreach (array_values($rows) as $row) {
            $row = is_array($row) ? $row : [];
            $benefit = $this->benefitOrFail((int) ($row['benefitId'] ?? 0));
            if (!$benefit->isActive()) {
                throw new AboException(422, 'BENEFIT_INACTIVE', sprintf('La prestation « %s » est désactivée.', $benefit->getName()), 'benefits');
            }
            $clean = ['benefitId' => (int) $benefit->getId()];
            foreach (['quantity', 'scheduling', 'slotChoice', 'cancelDeadlineHours', 'carryOverMax', 'proof'] as $key) {
                if (array_key_exists($key, $row)) {
                    $clean[$key] = $row[$key];
                }
            }
            $clean += ['quantity' => 1, 'scheduling' => 'none', 'cancelDeadlineHours' => 24, 'proof' => 'none'];
            $out[] = $clean;
        }

        return $out;
    }

    /** @param list<array<string, mixed>> $rows */
    private function rowsSummary(array $rows): string
    {
        return implode(' ; ', array_map(function (array $r) {
            $b = $this->benefits->benefits()->find((int) $r['benefitId']);

            return sprintf('%s × %s', $r['quantity'] === null ? 'sans limite' : (string) $r['quantity'], (string) $b?->getName());
        }, $rows));
    }

    private function fill(Benefit $benefit, array $data): void
    {
        foreach (['kind' => 'setKind', 'names' => 'setNames', 'descriptions' => 'setDescriptions', 'location' => 'setLocation'] as $key => $setter) {
            if (array_key_exists($key, $data)) {
                $benefit->$setter($key === 'kind' ? (string) $data[$key] : $data[$key]);
            }
        }
        if (array_key_exists('durationMinutes', $data)) {
            $benefit->setDurationMinutes($data['durationMinutes'] !== null ? (int) $data['durationMinutes'] : null);
        }
    }

    private function benefitOrFail(int $id): Benefit
    {
        return $this->benefits->benefits()->find($id) ?? throw new AboException(404, 'BENEFIT_NOT_FOUND', 'Prestation introuvable.', 'benefitId');
    }

    private function planOrFail(int $id): SubscriptionPlan
    {
        return $this->emProvider->getEntityManager()->getRepository(SubscriptionPlan::class)->find($id) ?? throw new AboException(404, 'PLAN_NOT_FOUND', 'Formule introuvable.', 'planId');
    }

    private static function kindLabel(string $kind): string
    {
        return match ($kind) { Benefit::KIND_SESSION => 'séance', Benefit::KIND_SERVICE => 'service', Benefit::KIND_QUOTA => 'quota', default => $kind };
    }

    private static function intervalLabel(string $interval): string
    {
        return match ($interval) { 'week' => 'semaine', 'year' => 'an', default => 'mois' };
    }
}

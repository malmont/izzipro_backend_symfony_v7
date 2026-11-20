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
    public const READS = ['get_subscription_plans', 'get_benefits', 'get_plan_subscribers', 'get_subscription', 'get_expiring_rights'];
    /** Lot ABO-1 : gestes du quotidien (résiliation EN FIN DE PÉRIODE seulement ; jamais immédiate, ni remboursement, ni retrait de droits payés) */
    public const ACTIONS = ['propose_benefit', 'propose_plan', 'propose_plan_update', 'set_plan_benefits', 'propose_use', 'propose_gift', 'propose_pause', 'propose_resume', 'propose_cancel_subscription'];
    public const MAX_BENEFITS = 10;

    public function __construct(private readonly BenefitService $benefits, private readonly AboFollowUpService $followUp, private readonly TenantEntityManagerProvider $emProvider,
        private readonly ?AboRightsService $rights = null, // lot ABO-1
        private readonly ?AboCatalogService $catalog = null) // lot ABO-CONF : le service commun des formules
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
            // --- lot ABO-CONF : modifier une formule (prix, rythme, archivage) ; jamais de prix sans confirmation
            ['name' => 'propose_plan_update', 'description' => 'Abonnements : propose de modifier une formule existante (planId) : prix en centimes, période, noms, ou archivage (active: false, elle n\'est plus vendue, les abonnés continuent). Un nouveau prix ne vaut que pour les NOUVEAUX abonnés. L\'administrateur confirme.', 'input_schema' => ['type' => 'object', 'properties' => [
                'planId' => ['type' => 'integer'], 'price' => ['type' => 'integer', 'description' => 'centimes'], 'interval' => ['type' => 'string', 'enum' => ['week', 'month', 'year']],
                'intervalCount' => ['type' => 'integer'], 'names' => ['type' => 'object'], 'active' => ['type' => 'boolean']], 'required' => ['planId']]],
            // --- lot ABO-1 : droits par période ; un abonné = son numéro, jamais son identité
            ['name' => 'get_expiring_rights', 'description' => 'Abonnements : les droits disponibles qui expirent dans les N prochains jours (7 par défaut, 30 au plus), par numéro d\'abonnement et prestation. Pour « qui a des séances qui expirent cette semaine ? ».', 'input_schema' => ['type' => 'object', 'properties' => ['days' => ['type' => 'integer']]]],
            ['name' => 'propose_use', 'description' => 'Abonnements : propose de marquer UNE utilisation d\'une prestation pour un abonnement (le droit qui expire le premier) ; annulable 24 h. L\'administrateur confirme.', 'input_schema' => ['type' => 'object', 'properties' => ['subscriptionId' => ['type' => 'integer'], 'benefitId' => ['type' => 'integer'], 'note' => ['type' => 'string']], 'required' => ['subscriptionId', 'benefitId']]],
            ['name' => 'propose_gift', 'description' => 'Abonnements : propose d\'OFFRIR 1 à 10 droits d\'une prestation à un abonnement (geste commercial), valables jusqu\'à la fin de la période ; motif obligatoire. L\'administrateur confirme.', 'input_schema' => ['type' => 'object', 'properties' => ['subscriptionId' => ['type' => 'integer'], 'benefitId' => ['type' => 'integer'], 'quantity' => ['type' => 'integer'], 'reason' => ['type' => 'string']], 'required' => ['subscriptionId', 'benefitId', 'quantity', 'reason']]],
            ['name' => 'propose_pause', 'description' => 'Abonnements : propose de mettre un abonnement en pause jusqu\'à une date (AAAA-MM-JJ, heure du magasin), dans la limite de la formule ; les droits disponibles sont prolongés d\'autant. L\'administrateur confirme.', 'input_schema' => ['type' => 'object', 'properties' => ['subscriptionId' => ['type' => 'integer'], 'until' => ['type' => 'string']], 'required' => ['subscriptionId', 'until']]],
            ['name' => 'propose_resume', 'description' => 'Abonnements : propose de reprendre un abonnement en pause. L\'administrateur confirme.', 'input_schema' => ['type' => 'object', 'properties' => ['subscriptionId' => ['type' => 'integer']], 'required' => ['subscriptionId']]],
            ['name' => 'propose_cancel_subscription', 'description' => 'Abonnements : propose de résilier un abonnement EN FIN DE PÉRIODE (jamais tout de suite) ; pendant l\'engagement minimal, un motif est exigé. L\'administrateur confirme.', 'input_schema' => ['type' => 'object', 'properties' => ['subscriptionId' => ['type' => 'integer'], 'reason' => ['type' => 'string']], 'required' => ['subscriptionId']]],
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
                'get_expiring_rights' => [['rights' => $this->expiring(max(1, min(30, (int) ($input['days'] ?? 7))))], 'subscriptions'],
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
                    $names = is_array($input['names'] ?? null) ? array_map('strval', array_filter($input['names'], 'is_scalar')) : []; // un nom de forme inattendue est écarté, jamais converti de force
                    $price = (int) ($input['price'] ?? -1);
                    $interval = (string) ($input['interval'] ?? '');
                    if (trim((string) ($names['fr'] ?? reset($names) ?: '')) === '' || $price < 0 || !in_array($interval, SubscriptionPlan::INTERVALS, true)) {
                        throw new AboException(422, 'PLAN_INVALID', 'Formule incomplète : un nom, un prix en centimes (0 ou plus) et une période (semaine, mois, année).');
                    }
                    $rows = $this->benefitRows($input['benefits'] ?? []);
                    $data = ['productId' => (int) $product->getId(), 'names' => $names, 'price' => $price, 'interval' => $interval, 'intervalCount' => max(1, (int) ($input['intervalCount'] ?? 1)), 'benefits' => $rows];

                    return [$data, sprintf('Créer la formule « %s » sur « %s » : %s %s par %s%s, avec %s', (string) ($names['fr'] ?? reset($names)), (string) $product->getName(),
                        number_format($price / 100, 2, ',', ' '), $this->catalog?->siteCurrency() ?? $this->currencyFor($product), self::intervalLabel($interval), // la devise imposée : celle du site $data['intervalCount'] > 1 ? ' (tous les ' . $data['intervalCount'] . ')' : '',
                        $this->rowsSummary($rows)), null, 'subscription_plans'];
                case 'propose_plan_update':
                    $plan = $this->planOrFail((int) ($input['planId'] ?? 0));
                    $data = array_intersect_key($input, array_flip(['price', 'interval', 'intervalCount', 'names', 'active']));
                    if ($data === []) {
                        throw new AboException(422, 'PLAN_INVALID', 'Rien à modifier : un prix, une période, des noms ou l\'archivage.');
                    }
                    if (isset($data['names'])) {
                        $data['names'] = is_array($data['names']) ? array_map('strval', array_filter($data['names'], 'is_scalar')) : [];
                    }
                    $preview = ($this->catalog ?? throw new AboException(503, 'TOOL_UNAVAILABLE', 'Outil indisponible.'))->preview($plan, $data, 'fr');

                    // relecture neuve : l'aperçu dit TOUT le changement (nouveau nom, archivage), pas seulement le prix
                    $changes = array_filter([
                        isset($data['names']) ? sprintf('nouveau nom « %s »', (string) ($data['names']['fr'] ?? reset($data['names']) ?: '')) : null,
                        array_key_exists('active', $data) ? ($data['active'] === false ? 'ARCHIVER la formule (elle n\'est plus vendue ; les abonnés continuent)' : 'remettre la formule en vente') : null,
                    ]);

                    return [['planId' => (int) $plan->getId(), 'data' => $data], sprintf('Modifier la formule « %s »%s : %s%s', $plan->getName(), $changes !== [] ? ' (' . implode(' ; ', $changes) . ')' : '',
                        $preview['summary'], $preview['priceChange'] !== null ? ' ' . $preview['priceChange'] : ''), null, 'subscription_plans'];
                case 'set_plan_benefits':
                    $plan = $this->planOrFail((int) ($input['planId'] ?? 0));
                    if ($plan->hasFulfillment()) {
                        throw new AboException(403, 'PLAN_PLATFORM_ONLY', 'Cette formule est branchée sur un service de la plateforme : elle se règle dans EasyAdmin.');
                    }
                    $rows = $this->benefitRows($input['benefits'] ?? []);

                    return [['planId' => (int) $plan->getId(), 'benefits' => $rows], sprintf('Régler les prestations de la formule « %s » : %s', $plan->getName(), $this->rowsSummary($rows)), null, 'abo_plan_benefits'];
                case 'propose_use':
                case 'propose_gift':
                    $sub = $this->subscriptionOrFail((int) ($input['subscriptionId'] ?? 0));
                    $benefitId = (int) ($input['benefitId'] ?? 0);
                    $line = array_values(array_filter($this->rights?->balance($sub, 'fr') ?? [], fn (array $b) => $b['benefitId'] === $benefitId))[0]
                        ?? throw new AboException(404, 'BENEFIT_NOT_IN_PLAN', 'Cette prestation n\'est pas dans la formule de l\'abonnement.', 'benefitId');
                    if ($name === 'propose_use') {
                        if (!$line['unlimited'] && (int) $line['available'] < 1) {
                            throw new AboException(409, 'BALANCE_EMPTY', 'Plus aucun droit disponible pour cette prestation.', 'benefitId');
                        }

                        return [['subscriptionId' => (int) $sub->getId(), 'benefitId' => $benefitId, 'note' => isset($input['note']) ? mb_substr((string) $input['note'], 0, 300) : null],
                            sprintf('Marquer une utilisation de « %s » pour l\'abonnement n° %d%s (annulable 24 h)', $line['name'], $sub->getId(), $line['unlimited'] ? '' : sprintf(', il en restera %d', (int) $line['available'] - 1)), null, 'subscriptions'];
                    }
                    $quantity = (int) ($input['quantity'] ?? 0);
                    $reason = trim(strip_tags((string) ($input['reason'] ?? '')));
                    if ($quantity < 1 || $quantity > AboRightsService::GIFT_MAX || $reason === '' || $line['unlimited']) {
                        throw new AboException(422, 'GIFT_INVALID', sprintf('De 1 à %d droits offerts, avec un motif, sur une prestation à quantité.', AboRightsService::GIFT_MAX), 'quantity');
                    }

                    return [['subscriptionId' => (int) $sub->getId(), 'benefitId' => $benefitId, 'quantity' => $quantity, 'reason' => mb_substr($reason, 0, 300)],
                        sprintf('Offrir %d « %s » à l\'abonnement n° %d (motif : %s)', $quantity, $line['name'], $sub->getId(), mb_substr($reason, 0, 80)), null, 'subscriptions'];
                case 'propose_pause':
                    $sub = $this->subscriptionOrFail((int) ($input['subscriptionId'] ?? 0));
                    $check = ($this->rights ?? throw new AboException(422, 'TOOL_UNKNOWN', 'Outil indisponible.'))->pauseUntil($sub, isset($input['until']) ? (string) $input['until'] : null);

                    return [['subscriptionId' => (int) $sub->getId(), 'until' => $check['until']->format('Y-m-d')],
                        sprintf('Mettre l\'abonnement n° %d en pause jusqu\'au %s (%d jours ; droits prolongés d\'autant)', $sub->getId(), $check['until']->format('d/m/Y'), $check['days']), null, 'subscriptions'];
                case 'propose_resume':
                    $sub = $this->subscriptionOrFail((int) ($input['subscriptionId'] ?? 0));
                    if ($sub->getStatus() !== \App\Entity\Subscription::STATUS_PAUSED) {
                        throw new AboException(409, 'SUBSCRIPTION_NOT_PAUSED', 'Cet abonnement n\'est pas en pause.');
                    }

                    return [['subscriptionId' => (int) $sub->getId()], sprintf('Reprendre l\'abonnement n° %d', $sub->getId()), null, 'subscriptions'];
                case 'propose_cancel_subscription':
                    $sub = $this->subscriptionOrFail((int) ($input['subscriptionId'] ?? 0));
                    $reason = trim(strip_tags((string) ($input['reason'] ?? '')));
                    if (($block = $this->rights?->minimumTermBlock($sub)) !== null && $reason === '') {
                        throw new AboException(422, 'REASON_REQUIRED', $block->getMessage() . ' Pour résilier quand même, donnez un motif.', 'reason', previous: $block);
                    }

                    return [['subscriptionId' => (int) $sub->getId(), 'reason' => $reason !== '' ? mb_substr($reason, 0, 300) : null],
                        sprintf('Résilier l\'abonnement n° %d EN FIN DE PÉRIODE%s', $sub->getId(), $sub->getCurrentPeriodEnd() !== null ? ' (' . $sub->getCurrentPeriodEnd()->format('d/m/Y') . ')' : ''), null, 'subscriptions'];
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
        // un refus de l'abonnement (statut, Stripe) ou un abonnement tenu par un autre traitement : un refus lisible, jamais une erreur 500
        try {
            return $this->applyTool($name, $input);
        } catch (\App\Services\SubscriptionService\SubscriptionException $e) {
            throw new AboException($e->getStatusCode(), $e->code(), $e->getMessage(), previous: $e);
        } catch (\App\Services\SubscriptionService\SubscriptionLockTimeout) {
            throw new AboException(409, 'SUBSCRIPTION_BUSY', 'Cet abonnement est en cours de traitement : réessayez dans un instant.');
        }
    }

    private function applyTool(string $name, array $input): array
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
                // lot ABO-CONF : par le service commun (mêmes contrôles que la gestion web et EasyAdmin, devise du site, historique des prix)
                $catalog = $this->catalog ?? throw new AboException(503, 'TOOL_UNAVAILABLE', 'Outil indisponible.');
                $plan = $em->wrapInTransaction(function () use ($em, $catalog, $product, $input, $rows) {
                    $plan = $catalog->savePlan(null, ['productId' => (int) $product->getId(), 'names' => array_map('strval', array_filter((array) ($input['names'] ?? []), 'is_scalar')),
                        'price' => (int) ($input['price'] ?? 0), 'interval' => (string) ($input['interval'] ?? 'month'), 'intervalCount' => max(1, (int) ($input['intervalCount'] ?? 1))], 'assistant');
                    foreach ($rows as $row) {
                        $this->benefits->setPlanBenefit($plan, $this->benefitOrFail((int) $row['benefitId']), $row, false);
                    }
                    $em->flush();

                    return $plan;
                });

                return ['planId' => (int) $plan->getId(), 'created' => 1, 'benefits' => count($rows)];
            case 'propose_plan_update':
                $plan = ($this->catalog ?? throw new AboException(503, 'TOOL_UNAVAILABLE', 'Outil indisponible.'))->savePlan($this->planOrFail((int) ($input['planId'] ?? 0)), is_array($input['data'] ?? null) ? $input['data'] : [], 'assistant');

                return ['planId' => (int) $plan->getId(), 'written' => 1];
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
            // lot ABO-1 : par le service des droits, tout relu (solde, limites de pause, engagement minimal) ; « par l'assistant »
            case 'propose_use':
                $r = $this->rightsOrFail()->use($this->subscriptionOrFail((int) ($input['subscriptionId'] ?? 0)), (int) ($input['benefitId'] ?? 0), $input['note'] ?? null, 'assistant');

                return ['written' => 1, 'occurrenceId' => $r['occurrenceId']];
            case 'propose_gift':
                return ['created' => $this->rightsOrFail()->gift($this->subscriptionOrFail((int) ($input['subscriptionId'] ?? 0)), (int) ($input['benefitId'] ?? 0), (int) ($input['quantity'] ?? 0), $input['reason'] ?? null, 'assistant')];
            case 'propose_pause':
                $this->rightsOrFail()->pause($this->subscriptionOrFail((int) ($input['subscriptionId'] ?? 0)), isset($input['until']) ? (string) $input['until'] : null, 'assistant');

                return ['written' => 1];
            case 'propose_resume':
                $this->rightsOrFail()->resume($this->subscriptionOrFail((int) ($input['subscriptionId'] ?? 0)), 'assistant');

                return ['written' => 1];
            case 'propose_cancel_subscription':
                $this->rightsOrFail()->cancelAtPeriodEnd($this->subscriptionOrFail((int) ($input['subscriptionId'] ?? 0)), true, $input['reason'] ?? null, 'assistant');

                return ['written' => 1];
        }
        throw new AboException(422, 'TOOL_UNKNOWN', 'Outil inconnu.');
    }

    /** @return list<array{subscriptionId: int, benefit: string, count: int, validUntil: string}> */
    private function expiring(int $days): array
    {
        $rows = $this->emProvider->getEntityManager()->getConnection()->fetchAllAssociative("SELECT o.subscription_id, o.benefit_id, o.valid_until, COUNT(*) AS n FROM benefit_occurrence o
            WHERE o.status = 'available' AND o.valid_until > ? AND o.valid_until <= ? GROUP BY o.subscription_id, o.benefit_id, o.valid_until ORDER BY o.valid_until LIMIT 100",
            [gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s', time() + $days * 86400)]); // bornes en UTC, comme les dates rangées (jamais le fuseau de la session)
        $names = [];

        return array_map(function (array $r) use (&$names) {
            $names[$r['benefit_id']] ??= (string) $this->benefits->benefits()->find((int) $r['benefit_id'])?->getName('fr');

            return ['subscriptionId' => (int) $r['subscription_id'], 'benefit' => $names[$r['benefit_id']], 'count' => (int) $r['n'],
                'validUntil' => (string) ($this->rights?->at(new \DateTimeImmutable((string) $r['valid_until'], new \DateTimeZone('UTC'))) ?? $r['valid_until'])]; // heure du magasin
        }, $rows);
    }

    private function subscriptionOrFail(int $id): \App\Entity\Subscription
    {
        return $this->emProvider->getEntityManager()->getRepository(\App\Entity\Subscription::class)->find($id) ?? throw new AboException(404, 'SUBSCRIPTION_NOT_FOUND', 'Abonnement introuvable.', 'subscriptionId');
    }

    private function rightsOrFail(): AboRightsService
    {
        return $this->rights ?? throw new AboException(422, 'TOOL_UNKNOWN', 'Outil indisponible.');
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

<?php

namespace App\UseCase\SiteAssistantUseCase;

use App\Services\Platform\CustomDomainService;
use App\Services\SiteAssistantService\SiteAssistantService;
use App\Services\SiteAssistantService\SiteAssistantTools;
use App\Services\TenantConnectionManager;
use App\UseCase\SiteFeaturesUseCase\RequestSiteFeatureUseCase;
use App\UseCase\SiteFeaturesUseCase\UpdateSiteFeaturesUseCase;

/**
 * POST /api/site/assistant/actions/{id}/confirm : applique une action proposée par l'assistant, par les mêmes cas d'usage que la
 * page Mon site et PUT /api/site/features (validation, rôles, journal). L'action est oubliée après exécution (ou refus).
 * Une action `setup_batch` (assistant qui enchaîne, 07/11/2026) applique ses étapes dans l'ordre, chacune par le même chemin
 * que si elle avait été proposée seule ; une étape refusée n'arrête pas les suivantes et le résultat dit ce qui est passé.
 */
class ConfirmSiteAssistantActionUseCase
{
    public function __construct(private readonly SiteAssistantService $assistant, private readonly UpdateSiteFeaturesUseCase $update, private readonly RequestSiteFeatureUseCase $request, private readonly CustomDomainService $domains, private readonly TenantConnectionManager $tenants, private readonly ?\App\Services\ShippingService\ProductDimensionsService $dimensions = null,
        // opérations sur les commandes (console IA, lot 6, 07/11/2026)
        private readonly ?\App\Services\OrderService\OrderOperationsService $operations = null, private readonly ?\App\Services\OrderService\OrderShipmentService $shipments = null, private readonly ?\App\Services\TenantEntityManagerProvider $emProvider = null,
        private readonly ?\App\UseCase\EntrepriseUsecase\UpdateEntrepriseUseCase $entreprise = null, // fiche Entreprise (cheminement complet, 07/11/2026)
        // lot IA-A (17/11/2026) : actions des fonctions ajoutées, chacune par le service de son écran ; tracées « par l'assistant »
        private readonly ?\App\Services\FeatureAccess\ModuleAccessService $modules = null,
        private readonly ?\App\Services\LandingContentService\BoutiqueCatalogEditor $catalog = null,
        private readonly ?\App\LossPrevention\Services\LossExerciseService $lossExercise = null,
        private readonly ?\App\LossPrevention\Services\LossCountService $lossCount = null,
        private readonly ?\App\Services\BarcodeService\CatalogBarcodeService $barcodes = null,
        private readonly ?\App\Services\Agency\Assistance\AssistanceService $assistance = null,
        private readonly ?\App\Services\ContentAuditService\ContentAuditRecorder $audit = null,
        private readonly ?\App\Security\Entry\EntryContext $entry = null,
        private readonly ?\App\Abo\Services\AboAssistantTools $abo = null, // lot ABO-0 : formules et prestations
        // lot IA-G (19/11/2026) : actions du directeur du groupe
        private readonly ?\App\Services\Group\AllShops\GroupDirector $groupDirector = null,
        private readonly ?\App\Services\Group\GroupService $groupService = null,
        private readonly ?\App\Services\Group\Copy\GroupCatalogCopyService $groupCopies = null,
        private readonly ?\Psr\Log\LoggerInterface $logger = null,
        private readonly ?\App\Services\Group\GroupCompanyService $groupCompany = null, // lot G-1d
        private readonly ?\App\Services\Group\Transfer\GroupTransferService $groupTransfers = null, // lot G-5
        private readonly ?\App\Services\ReservationService\ShopBookingAssistantTools $shopBookings = null, // lot CMD-WEB-1
        private readonly ?\App\Services\CaisseService\SiteLocaleResolver $siteLocale = null, // lot CMD-WEB-1 : la langue du courriel au client
    ) {
    }

    /**
     * @return array{tool: string, input: array<string, mixed>, result: array<string, mixed>}
     * @throws \OutOfBoundsException action inconnue ou expirée
     */
    public function execute(string $id, string $host, ?string $user, ?\App\Entity\User $actor = null): array
    {
        $tenant = (string) $this->tenants->getCurrentTenantCode();
        $action = $this->assistant->pendingAction($tenant, $id);
        if ($action === null) {
            throw new \OutOfBoundsException('Action inconnue ou expirée : demandez à nouveau à l\'assistant.');
        }
        if ($action['tool'] === 'propose_booking_status') {
            // lot CMD-WEB-1 : jamais le directeur entré (il lit les rendez-vous, il ne les confirme ni ne les annule) ; tout relu sous le verrou ;
            // un refus ne consume pas l'action
            if ($this->shopBookings === null || ($this->entry?->entry()['mode'] ?? null) === \App\Services\Entry\EntryService::MODE_GROUP) {
                throw new \InvalidArgumentException('Seul un administrateur de la boutique confirme ou annule un rendez-vous.');
            }
            try {
                $result = $this->shopBookings->apply($action['input'], trim((string) $actor?->getFirstname()) ?: 'assistant', $this->siteLocale?->siteLocale() ?? 'fr', $host);
            } catch (\App\Services\ReservationService\ShopBookingException $e) {
                throw new \InvalidArgumentException($e->getMessage() . ' (' . $e->errorCode . ')', 0, $e);
            }
            $this->audit?->record('site_assistant', $id, 'confirm', null, ['tool' => $action['tool'], 'by' => 'assistant', 'confirmedBy' => trim((string) $actor?->getFirstname()) ?: 'administrateur',
                'summary' => $action['summary'], 'result' => $result], ['assistant']);
            $this->assistant->forgetAction($tenant, $id);

            return ['tool' => $action['tool'], 'input' => $action['input'], 'result' => $result];
        }
        if (in_array($action['tool'], SiteAssistantTools::IAA_ACTIONS, true)) {
            // lot IA-A : tout est relu à la confirmation (module, état, compte) ; un refus ne consume pas l'action, la trace dit qui a confirmé
            $result = $this->added($action['tool'], $action['input'], $tenant, $user, $actor, $host);
            $this->audit?->record('site_assistant', $id, 'confirm', null, ['tool' => $action['tool'], 'by' => 'assistant', 'confirmedBy' => trim((string) $actor?->getFirstname()) ?: 'administrateur',
                'summary' => $action['summary'], 'result' => array_intersect_key($result, array_flip(['created', 'written', 'remaining', 'scenario', 'countId', 'feature', 'on', 'kind', 'assistance', 'planId', 'benefitId', 'benefits']))], ['assistant']);
            $this->assistant->forgetAction($tenant, $id);

            return ['tool' => $action['tool'], 'input' => $action['input'], 'result' => $result];
        }
        $result = $action['tool'] === 'setup_batch'
            ? $this->batch(is_array($action['input']['steps'] ?? null) ? $action['input']['steps'] : [], $tenant, $host, $user)
            : $this->apply($action['tool'], $action['input'], $tenant, $host, $user);
        $this->assistant->forgetAction($tenant, $id);

        return ['tool' => $action['tool'], 'input' => $action['input'], 'result' => $result];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function apply(string $tool, array $input, string $tenant, string $host, ?string $user): array
    {
        return match ($tool) {
            'set_site_settings' => $this->settings($input['values'] ?? [], $host),
            'request_feature' => $this->request->execute((string) ($input['feature'] ?? ''), $input['message'] ?? null, $user),
            'set_custom_domain' => ['domain' => $this->domains->set($tenant, (string) ($input['domain'] ?? ''), $user)],
            // poids estimés par l'IA (lot 1 bis, 07/11/2026) : mêmes règles que la grille « Poids et dimensions »
            'apply_product_dimensions' => $this->dimensions?->save(is_array($input['rows'] ?? null) ? $input['rows'] : [], 'assistant') ?? throw new \OutOfBoundsException('Outil indisponible'),
            // opérations de support (lot 6) : mêmes services et mêmes refus que les pages de la commande ; un refus devient 422
            'refund_order', 'cancel_order', 'capture_order', 'resend_order_email', 'ship_order' => $this->operation($tool, $input, $host, $user),
            'set_entreprise_fields' => $this->entrepriseFields(is_array($input['fields'] ?? null) ? $input['fields'] : [], $host),
            default => throw new \OutOfBoundsException('Outil inconnu'),
        };
    }

    /**
     * Étapes d'un enchaînement, dans l'ordre ; chaque étape passe par `apply` (mêmes validations et mêmes refus).
     *
     * @param list<array<string, mixed>> $steps
     * @return array{steps: list<array<string, mixed>>, applied: int, failed: int}
     * @throws \InvalidArgumentException si aucune étape n'a pu être appliquée
     */
    private function batch(array $steps, string $tenant, string $host, ?string $user): array
    {
        $out = [];
        foreach ($steps as $step) {
            $tool = (string) ($step['tool'] ?? '');
            $summary = mb_substr((string) ($step['summary'] ?? ''), 0, 200);
            if (!in_array($tool, SiteAssistantTools::BATCH_TOOLS, true)) {
                $out[] = ['tool' => $tool, 'summary' => $summary, 'ok' => false, 'error' => 'Outil non enchaînable.'];
                continue;
            }
            try {
                $out[] = ['tool' => $tool, 'summary' => $summary, 'ok' => true, 'result' => $this->apply($tool, is_array($step['input'] ?? null) ? $step['input'] : [], $tenant, $host, $user)];
            } catch (\Throwable $e) {
                $out[] = ['tool' => $tool, 'summary' => $summary, 'ok' => false, 'error' => $e->getMessage() ?: 'Étape refusée.'];
            }
        }
        $applied = count(array_filter($out, fn ($s) => $s['ok']));
        if ($applied === 0) {
            throw new \InvalidArgumentException($out === [] ? 'Aucune étape à appliquer.' : 'Aucune étape appliquée : ' . (string) $out[0]['error']);
        }

        return ['steps' => $out, 'applied' => $applied, 'failed' => count($out) - $applied];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function operation(string $tool, array $input, string $host, ?string $user): array
    {
        if ($this->operations === null || $this->shipments === null || $this->emProvider === null) {
            throw new \OutOfBoundsException('Outil indisponible');
        }
        $order = $this->emProvider->getEntityManager()->getRepository(\App\Entity\Order::class)->find((int) ($input['orderId'] ?? 0));
        if ($order === null) {
            throw new \InvalidArgumentException('Commande introuvable.');
        }
        $domain = preg_replace('#^https?://#', '', $host) ?: $host;
        $result = match ($tool) {
            'refund_order' => $this->operations->refund($order, (int) ($input['amountCents'] ?? 0), (string) ($input['reason'] ?? ''), (bool) ($input['notify'] ?? true), $user, $domain),
            'cancel_order' => $this->operations->cancel($order, (string) ($input['reason'] ?? ''), (bool) ($input['notify'] ?? true), $user, $domain),
            'capture_order' => $this->operations->capture($order, $user),
            'resend_order_email' => $this->operations->resend($order, (string) ($input['kind'] ?? ''), $domain),
            'ship_order' => $this->shipments->shipBlocker($order) === null
                ? ['status' => 200, 'body' => $this->shipments->ship($order, (string) ($input['carrier'] ?? ''), $input['trackingNumber'] ?? null, $input['trackingUrl'] ?? null, (bool) ($input['notify'] ?? true), $domain)]
                : ['status' => 409, 'body' => ['message' => $this->shipments->shipBlocker($order)]],
        };
        if (($result['status'] ?? 500) >= 400) {
            throw new \InvalidArgumentException((string) ($result['body']['message'] ?? 'Opération refusée.'));
        }

        return $result['body'] + ['orderId' => (int) $order->getId(), 'reference' => (string) $order->getReference()];
    }

    /** @param array<string, mixed> $fields @return array<string, mixed> */
    private function entrepriseFields(array $fields, string $host): array
    {
        if ($this->entreprise === null || $this->emProvider === null) {
            throw new \OutOfBoundsException('Outil indisponible');
        }
        $fields = array_intersect_key($fields, array_flip(SiteAssistantTools::ENTREPRISE_FIELDS));
        $id = (int) ($this->emProvider->getEntityManager()->getRepository(\App\Entity\Entreprise::class)->findOneBy([], ['id' => 'ASC'])?->getId() ?? 0);
        if ($id === 0 || $fields === []) {
            throw new \InvalidArgumentException('Fiche Entreprise introuvable ou aucun champ.');
        }
        $dto = $this->entreprise->execute($id, $fields, $host, 'fr');
        if ($dto === null) {
            throw new \InvalidArgumentException('Fiche Entreprise introuvable.');
        }

        return ['applied' => array_keys($fields), 'entrepriseId' => $id];
    }

    /**
     * Lot IA-A : une action des fonctions ajoutées, relue et appliquée par le service de son écran. Un refus métier devient
     * `\\InvalidArgumentException` (422, message clair) ; un outil sans service : `\\OutOfBoundsException`.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function added(string $tool, array $input, string $tenant, ?string $user, ?\App\Entity\User $actor, string $host = ''): array
    {
        $needs = SiteAssistantTools::MODULE_OF[$tool] ?? [];
        if ($needs !== [] && ($this->modules === null || array_filter($needs, fn (string $m): bool => $this->modules->isEnabled($m)) === [])) {
            throw new \InvalidArgumentException('Le module de cette action n\'est plus actif sur ce site.');
        }
        $userId = (int) ($actor?->getId() ?? 0);
        try {
            switch ($tool) {
                case 'add_default_variant':
                    if ($this->catalog === null || $this->emProvider === null) {
                        throw new \OutOfBoundsException('Outil indisponible');
                    }
                    $ids = array_slice(array_values(array_filter(array_map('intval', is_array($input['productIds'] ?? null) ? $input['productIds'] : []))), 0, SiteAssistantTools::MAX_VARIANTS);
                    $em = $this->emProvider->getEntityManager();
                    $created = [];
                    foreach ($ids as $productId) {
                        // relu : toujours en ligne, toujours avec un prix, toujours sans variante (sinon laissé tel quel)
                        $product = $em->getRepository(\App\Entity\Product::class)->find($productId);
                        if ($product === null || !$product->isWeb() || (float) $product->getPrice() <= 0 || count($product->getVariants()) > 0) {
                            continue;
                        }
                        $this->catalog->createVariant($product, (object) ['stockQuantity' => 0]);
                        $created[] = $productId;
                    }

                    return ['created' => count($created), 'productIds' => $created];
                case 'start_loss_exercise':
                    return ($this->lossExercise ?? throw new \OutOfBoundsException('Outil indisponible'))->start(['scenario' => (string) ($input['scenario'] ?? '')], $userId, 'fr') + ['scenario' => (string) ($input['scenario'] ?? '')];
                case 'start_loss_count':
                    $count = ($this->lossCount ?? throw new \OutOfBoundsException('Outil indisponible'))->open([], $userId, false, 'fr');

                    return $count + ['countId' => $count['id'] ?? ($count['count']['id'] ?? null)];
                case 'toggle_module':
                    return $this->request->switchTo((string) ($input['feature'] ?? ''), ($input['enabled'] ?? false) === true, $user) + ['feature' => (string) ($input['feature'] ?? '')];
                case 'start_module_trial':
                    return $this->request->trial((string) ($input['feature'] ?? ''), $user) + ['feature' => (string) ($input['feature'] ?? '')];
                case 'generate_missing_barcodes':
                    return ($this->barcodes ?? throw new \OutOfBoundsException('Outil indisponible'))->generateMissing(SiteAssistantTools::MAX_BARCODES, ['source' => 'assistant', 'confirmedBy' => trim((string) $actor?->getFirstname()) ?: 'administrateur']);
                case 'propose_group_invitation':
                case 'propose_group_copy':
                case 'propose_group_same_company':
                case 'propose_group_transfer':
                    // relu à la confirmation : toujours le directeur, sur le site du siège, avec son compte (jamais une entrée)
                    if ($actor === null || ($this->entry?->active() ?? false) || $this->groupDirector?->group() === null || $this->groupService === null || $this->groupCopies === null) {
                        throw new \InvalidArgumentException('Action réservée au directeur du groupe, sur le site du siège.');
                    }
                    try {
                        if ($tool === 'propose_group_transfer') {
                            // lot G-5 : la DEMANDE du directeur (mêmes règles que la route) ; la boutique source envoie elle-même
                            return ($this->groupTransfers ?? throw new \OutOfBoundsException('Outil indisponible'))->request($actor, ['source' => $input['source'] ?? null, 'target' => $input['target'] ?? null, 'lines' => $input['lines'] ?? null]);
                        }
                        if ($tool === 'propose_group_same_company') {
                            // lot G-1d : la DEMANDE du directeur ; la boutique confirme elle-même
                            return ($this->groupCompany ?? throw new \OutOfBoundsException('Outil indisponible'))->request($tenant, (string) ($input['site'] ?? ''), $actor, 'fr');
                        }
                        if ($tool === 'propose_group_invitation') {
                            return $this->groupService->invite($tenant, (string) ($input['site'] ?? ''), $actor, 'fr');
                        }
                        $copy = $this->groupCopies->analyse($actor, array_filter(['source' => $input['source'] ?? null, 'target' => $input['target'] ?? null, 'articles' => $input['articles'] ?? null], static fn ($v) => $v !== null));

                        try {
                            return $this->groupCopies->confirm($actor, (int) $copy['id'], array_intersect_key($input, array_flip(['withCost', 'visibility', 'pos'])));
                        } catch (\App\Services\Group\GroupException $e) {
                            // relecture IA-G : un aperçu laissé ouvert bloquerait tout import de la cible (409 IMPORT_ALREADY_ACTIVE) ; il est annulé.
                            // Seulement sur un REFUS de la copie (GroupException : levé avant la mise en file) ; jamais sur une panne imprévue, qui
                            // peut survenir après la mise en file (annuler retirerait alors des articles déjà copiés)
                            try {
                                $this->groupCopies->cancel($actor, (int) $copy['id']);
                            } catch (\Throwable $cancel) {
                                $this->logger?->warning('[SiteAssistant] aperçu de copie non annulé après un refus', ['copy' => (int) $copy['id'], 'error' => $cancel::class]);
                            }
                            throw $e;
                        }
                    } catch (\App\Services\Group\GroupException $e) {
                        throw new \InvalidArgumentException($e->getMessage() . ' (' . $e->errorCode . ')', 0, $e);
                    }
                case 'request_agency_assistance':
                    // seul un administrateur DU site : ni le compte de la plateforme, ni une entrée par jeton
                    if ($this->assistance === null || $actor === null || ($this->entry?->active() ?? false) || \App\Services\Platform\PlatformAccessJournal::isPlatformAccount($actor->getRoles())) {
                        throw new \InvalidArgumentException('Seul un administrateur du site demande l\'assistance de son agence.');
                    }

                    return ['assistance' => $this->assistance->request($tenant, $input['section'] ?? null, $input['mode'] ?? null, $actor)];
                case 'propose_benefit':
                case 'propose_plan':
                case 'propose_subscription_product': // lot ABO-CONF-b
                case 'propose_plan_update':
                case 'set_plan_benefits':
                case 'propose_use':
                case 'propose_gift':
                case 'propose_pause':
                case 'propose_resume':
                case 'propose_cancel_subscription':
                case 'propose_slot_rule': // lot ABO-2
                case 'propose_assign_date':
                case 'propose_move_occurrence':
                case 'propose_mark_done': // lot ABO-3a (jamais le directeur entré : l'assistant lui est fermé)
                case 'propose_mark_session_done':
                case 'propose_reply': // lot ABO-3b : la réponse lue et confirmée par l'administrateur
                    // lot ABO-0 : par le service de l'écran, tout relu (formule, produit porteur, prestations actives) ; un refus garde son message
                    return ($this->abo ?? throw new \OutOfBoundsException('Outil indisponible'))->apply($tool, $input, $host);
            }
        } catch (\App\Abo\Services\AboException|\App\Gestion\Services\GestionConflictException|\App\Gestion\Services\GestionValidationException|\App\Services\Agency\Assistance\AssistanceException|\App\UseCase\LandingContentUseCase\LandingContentException $e) {
            throw new \InvalidArgumentException($e->getMessage() !== '' ? $e->getMessage() : 'Action refusée.', 0, $e);
        }

        throw new \OutOfBoundsException('Outil inconnu');
    }

    /** @param array<string, mixed> $values */
    private function settings(array $values, string $host): array
    {
        $this->update->execute($values, $host, 'fr');

        return ['applied' => array_keys($values)];
    }
}

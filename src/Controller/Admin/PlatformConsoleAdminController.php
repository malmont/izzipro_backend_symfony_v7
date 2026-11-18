<?php

namespace App\Controller\Admin;

use App\Security\RoleAssignmentPolicy;
use App\Services\FeatureAccess\FeatureCatalog;
use App\Services\Platform\EntitlementRepository;
use App\Services\Platform\FeatureRequestRepository;
use App\Services\Platform\PlatformConsoleService;
use App\Services\Platform\PlatformModeService;
use App\Services\Platform\PlatformOfferRepository;
use App\UseCase\PlatformUseCase\ManagePlatformEntitlementsUseCase;
use App\UseCase\PlatformUseCase\PlatformValidationException;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Onglet « Plateforme » (console de pilotage, lot 4, 04/11/2026), super administrateur : mode demo | live, grille sites × fonctionnalités
 * avec source et échéance de chaque droit, accorder / essai / gratuit, suspendre / rétablir / annuler / prolonger, politiques, offres et
 * prix, demandes des sites, journal. Chaque action POST (jeton CSRF) passe par ManagePlatformEntitlementsUseCase, comme l'API.
 * Habillage EasyAdmin depuis le menu (/admin?routeName=admin_plateforme).
 */
class PlatformConsoleAdminController extends AbstractController
{
    private const CSRF_ID = 'admin_plateforme';

    public function __construct(
        private readonly PlatformConsoleService $console,
        private readonly ManagePlatformEntitlementsUseCase $manage,
        private readonly PlatformModeService $mode,
        private readonly PlatformOfferRepository $offers,
        private readonly FeatureRequestRepository $requests,
        private readonly EntitlementRepository $repository,
        private readonly AdminUrlGenerator $urls,
        private readonly ?\App\Services\Platform\PlatformShippingService $shipping = null, // étiquettes par la plateforme (lot 2 Livraison, 07/11/2026)
        private readonly ?\App\Services\Platform\ShippingBalanceRepository $balances = null,
        private readonly ?\App\Services\Platform\PlatformAiCreditsService $aiCredits = null, // crédits IA par site (07/11/2026)
        private readonly ?\Symfony\Component\Messenger\MessageBusInterface $bus = null, // lot MOD-1 : « Retirer et résilier »
        private readonly ?\App\LossPrevention\Master\LossHardwareProductRepository $hardware = null, // lot L6-a : matériel de la prévention des pertes
        private readonly ?\App\LossPrevention\UseCase\LossShipmentUseCase $lossShipments = null, // lot L6-b : boîtiers à expédier
    ) {
    }

    /** Crédits IA par défaut des sites sans réglage et durée de l'essai « Aide IA » à la création (IA bornée, 08/11/2026) */
    #[Route('/admin/plateforme/ia-defauts', name: 'admin_plateforme_ai_defaults', methods: ['POST'])]
    public function aiDefaults(Request $request): Response
    {
        return $this->action($request, function () use ($request) {
            $credits = (int) $request->request->get('defaultCredits', 0);
            $days = (int) $request->request->get('trialDays', 0);
            $this->mode->setAiDefaults($credits, $days, $this->getUser()?->getUserIdentifier());
            $this->addFlash('success', sprintf('Nouveaux sites : %d crédit(s) IA par mois, essai « Aide IA » de %d jour(s).', $credits, $days));
        });
    }

    #[Route('/admin/plateforme/ia-credits', name: 'admin_plateforme_ai_credits', methods: ['POST'])]
    public function aiCredits(Request $request): Response
    {
        return $this->action($request, function () use ($request) {
            $code = (string) $request->request->get('tenant', '');
            $monthly = (int) $request->request->get('monthly', 0);
            $this->aiCredits?->set($code, $monthly, $this->getUser()?->getUserIdentifier());
            $this->addFlash('success', sprintf('%s : %d crédit(s) IA par mois.', $code, $monthly));
        });
    }

    #[Route('/admin/plateforme/livraison/frais', name: 'admin_plateforme_shipping_fees', methods: ['POST'])]
    public function shippingFees(Request $request): Response
    {
        return $this->action($request, function () use ($request) {
            $bp = (int) round((float) str_replace(',', '.', (string) $request->request->get('feePercent', '0')) * 100);
            $fixed = (int) round((float) str_replace(',', '.', (string) $request->request->get('feeFixed', '0')) * 100);
            $this->shipping?->setFees($bp, $fixed, $this->getUser()?->getUserIdentifier());
            $carriers = [];
            foreach (preg_split('/\R/', (string) $request->request->get('carriers', '')) ?: [] as $line) {
                $parts = array_map('trim', explode('|', $line));
                if (($parts[0] ?? '') !== '') {
                    $carriers[] = ['id' => $parts[0], 'carrier' => $parts[1] ?? '', 'label' => $parts[2] ?? ($parts[1] ?? $parts[0])];
                }
            }
            $this->shipping?->setCarriers($carriers, $this->getUser()?->getUserIdentifier());
            $this->addFlash('success', 'Frais d\'étiquette et comptes transporteurs de la plateforme enregistrés.');
        });
    }

    #[Route('/admin/plateforme/livraison/credit', name: 'admin_plateforme_shipping_credit', methods: ['POST'])]
    public function shippingCredit(Request $request): Response
    {
        return $this->action($request, function () use ($request) {
            $amount = (int) round((float) str_replace(',', '.', (string) $request->request->get('amount', '0')) * 100);
            $code = trim((string) $request->request->get('tenant', ''));
            $result = $this->shipping?->credit($code, $amount, (string) $request->request->get('note', ''), $this->getUser()?->getUserIdentifier());
            $this->addFlash('success', sprintf('Solde d\'expédition de %s crédité : %s %s.', $code, number_format(($result['balanceCents'] ?? 0) / 100, 2, ',', ' '), $result['currency'] ?? 'CAD'));
        });
    }

    #[Route('/admin/plateforme', name: 'admin_plateforme', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyAccessUnlessGranted(RoleAssignmentPolicy::SUPER_ADMIN);
        // depuis le menu EasyAdmin, les paramètres de route arrivent dans les attributs (routeParams), sinon dans la requête
        $tenant = trim((string) ($request->query->get('site') ?? $request->attributes->get('site') ?? ''));
        $sites = $this->console->grid();
        $siteUrls = [];
        foreach ($sites as $s) {
            $siteUrls[$s['code']] = $this->pageUrl(['site' => $s['code']]);
        }

        return $this->render('admin/plateforme/index.html.twig', [
            'siteUrls' => $siteUrls,
            'mode' => $this->mode->settings(),
            'features' => array_keys(FeatureCatalog::GOVERNED),
            'labels' => array_combine(array_keys(FeatureCatalog::GOVERNED), array_map(fn ($k) => FeatureCatalog::label($k), array_keys(FeatureCatalog::GOVERNED))),
            'sites' => $sites,
            'policies' => $this->console->policies(),
            'offers' => $this->offers->all(false),
            'lossHardware' => $this->hardware?->all(false) ?? [],
            'lossShipments' => $this->lossShipmentsList(),
            // lot L6-b : l'aperçu d'une expédition, montré une fois après « Aperçu », avec le formulaire de confirmation
            'lossShipmentPreview' => $request->getSession()->getFlashBag()->get('loss_shipment_preview')[0] ?? null,
            'requests' => $this->requests->listPending(),
            'events' => $this->repository->events($tenant !== '' ? $tenant : null, 50),
            'selected' => $tenant,
            'csrf_id' => self::CSRF_ID,
            'shippingSettings' => $this->shipping?->settings() ?? ['feeBp' => 0, 'feeFixedCents' => 0, 'carriers' => []],
            'shippingBalances' => $this->balances?->all() ?? [],
            'aiCredits' => $this->aiCredits?->overview() ?? [],
            'aiDefaults' => $this->mode->settings(), // aiDefaultCredits, aiTrialDays (08/11/2026)
        ]);
    }

    #[Route('/admin/plateforme/mode', name: 'admin_plateforme_mode', methods: ['POST'])]
    public function switchMode(Request $request): Response
    {
        return $this->action($request, function () use ($request) {
            $mode = (string) $request->request->get('mode', '');
            $this->mode->setMode($mode, $this->getUser()?->getUserIdentifier());
            $this->addFlash('success', $mode === PlatformModeService::LIVE ? 'Plateforme en mode live : les garde-fous des modules et des droits d\'usage s\'appliquent.' : 'Plateforme en mode demo : aucune restriction.');
        });
    }

    #[Route('/admin/plateforme/boutique', name: 'admin_plateforme_shop', methods: ['POST'])]
    public function shop(Request $request): Response
    {
        return $this->action($request, function () use ($request) {
            $this->mode->setShopUrl((string) $request->request->get('shopUrl', ''), $this->getUser()?->getUserIdentifier());
            $this->mode->setShopTenant((string) $request->request->get('shopTenant', ''), $this->getUser()?->getUserIdentifier());
            $this->addFlash('success', 'Boutique de la plateforme enregistrée (site et adresse).');
        });
    }

    #[Route('/admin/plateforme/accorder', name: 'admin_plateforme_grant', methods: ['POST'])]
    public function grant(Request $request): Response
    {
        return $this->action($request, function () use ($request) {
            $row = $this->manage->grant($request->request->all(), $this->getUser()?->getUserIdentifier());
            $this->addFlash('success', sprintf('%s : %s accordé (%s%s).', $row['tenant_code'], FeatureCatalog::label($row['feature_key']), $row['source'], $row['ends_at'] ? ' jusqu\'au ' . substr((string) $row['ends_at'], 0, 10) : ', sans fin'));
        });
    }

    #[Route('/admin/plateforme/droit/{id}/{action}', name: 'admin_plateforme_act', methods: ['POST'], requirements: ['id' => '\d+', 'action' => 'suspend|resume|cancel|extend'])]
    public function act(int $id, string $action, Request $request): Response
    {
        return $this->action($request, function () use ($id, $action, $request) {
            $data = ['action' => $action, 'note' => $request->request->get('note')];
            if ($action === 'extend') {
                $data['endsAt'] = $request->request->get('endsAt');
            }
            $row = $this->manage->act($id, $data, $this->getUser()?->getUserIdentifier());
            $this->addFlash('success', sprintf('Droit n° %d (%s, %s) : %s.', $id, $row['tenant_code'], FeatureCatalog::label($row['feature_key']), $row['status']));
            // lot MOD-1 : un droit payé par un abonnement se rallumerait au renouvellement ; « Retirer et résilier » résilie l'abonnement à
            // la fin de la période (worker placé sur le site vendeur)
            if ($action === 'cancel' && $request->request->get('resilier') === '1' && ($row['source'] ?? null) === 'subscription' && str_starts_with((string) ($row['reference'] ?? ''), 'subscription:')) {
                $this->bus?->dispatch(new \App\Message\CancelPlatformSubscriptionMessage((string) $row['reference'], $this->getUser()?->getUserIdentifier()));
                $offer = ($row['offer_code'] ?? null) !== null ? $this->offers->find((string) $row['offer_code']) : null;
                $features = array_map(static fn (string $key): string => FeatureCatalog::label($key), is_array($offer['features'] ?? null) ? $offer['features'] : []);
                $this->addFlash('success', 'Résiliation de TOUT l\'abonnement demandée : il prendra fin à la fin de la période payée' . ($features !== [] ? ' ; s\'éteindront alors : ' . implode(', ', $features) . '.' : '.'));
            } elseif ($action === 'cancel' && $request->request->get('resilier') === '1') {
                $this->addFlash('warning', 'Droit retiré, mais aucun abonnement à résilier n\'a été trouvé pour ce droit.');
            }
        });
    }

    #[Route('/admin/plateforme/politique/{feature}', name: 'admin_plateforme_policy', methods: ['POST'], requirements: ['feature' => '[a-z_]+\.[a-z_]+'])]
    public function policy(string $feature, Request $request): Response
    {
        return $this->action($request, function () use ($feature, $request) {
            $this->manage->policy($feature, ['trialAllowed' => $request->request->getBoolean('trialAllowed'), 'trialDays' => $request->request->get('trialDays'), 'freeAllowed' => $request->request->getBoolean('freeAllowed'), 'graceDays' => $request->request->get('graceDays')], $this->getUser()?->getUserIdentifier());
            $this->addFlash('success', sprintf('Politique de %s enregistrée.', FeatureCatalog::label($feature)));
        });
    }

    #[Route('/admin/plateforme/offre/{code}', name: 'admin_plateforme_offer', methods: ['POST'], requirements: ['code' => '[a-z0-9_]+'])]
    public function offer(string $code, Request $request): Response
    {
        return $this->action($request, function () use ($code, $request) {
            $values = ['monthlyPriceCents' => (int) round(((float) str_replace(',', '.', (string) $request->request->get('monthlyPrice', '0'))) * 100), 'trialDays' => (int) $request->request->get('trialDays', 0), 'isActive' => $request->request->getBoolean('isActive')];
            $yearly = trim((string) $request->request->get('yearlyPrice', ''));
            $values['yearlyPriceCents'] = $yearly === '' ? null : (int) round(((float) str_replace(',', '.', $yearly)) * 100);
            $errors = $this->offers->upsert($code, $values, $this->getUser()?->getUserIdentifier());
            if ($errors !== []) {
                throw new PlatformValidationException($errors);
            }
            $this->addFlash('success', sprintf('Offre %s enregistrée.', $code));
        });
    }

    /**
     * Lot L6-a (17/11/2026, `docs/prevention-pertes.md` § 19.4) : le prix, le lien de la fiche dans la boutique de forfaits et la mise en
     * vente d'un article de matériel ; la même validation que `PUT /api/platform/pertes/materiel/{code}`. Prix vide : pas de prix.
     */
    #[Route('/admin/plateforme/materiel/{code}', name: 'admin_plateforme_loss_hardware', methods: ['POST'], requirements: ['code' => '[a-z0-9_]{2,64}'])]
    public function lossHardware(string $code, Request $request): Response
    {
        return $this->action($request, function () use ($code, $request) {
            if ($this->hardware === null || $this->hardware->find($code) === null) {
                throw new \InvalidArgumentException(sprintf('Article de matériel %s inconnu.', $code));
            }
            $price = trim((string) $request->request->get('price', ''));
            $url = trim((string) $request->request->get('buyUrl', ''));
            $values = ['priceCents' => $price === '' ? null : (is_numeric(str_replace(',', '.', $price)) ? (int) round(((float) str_replace(',', '.', $price)) * 100) : -1),
                'buyUrl' => $url === '' ? null : $url, 'isActive' => $request->request->getBoolean('isActive')];
            $errors = $this->hardware->upsert($code, $values, $this->getUser()?->getUserIdentifier());
            if ($errors !== []) {
                throw new PlatformValidationException($errors);
            }
            $this->addFlash('success', sprintf('Matériel %s enregistré.', $code));
        });
    }

    /**
     * Lot L6-b (17/11/2026, `docs/prevention-pertes.md` § 19.5) : réserver un boîtier vendu pour un site, en deux temps. « Aperçu » montre le
     * site visé (nom, domaine, module) sans rien écrire ; « Confirmer » réserve. Les mêmes règles que `POST /api/platform/pertes/expeditions`.
     */
    #[Route('/admin/plateforme/expedition', name: 'admin_plateforme_loss_shipment', methods: ['POST'])]
    public function lossShipment(Request $request): Response
    {
        return $this->action($request, function () use ($request) {
            $body = ['serial' => trim((string) $request->request->get('serial', '')), 'code' => trim((string) $request->request->get('code', '')),
                'tenantCode' => trim((string) $request->request->get('tenantCode', '')), 'orderRef' => trim((string) $request->request->get('orderRef', ''))];
            $confirm = $request->request->get('step') === 'confirmer';
            // relecture 2 : le code relu à la confirmation doit être celui de l'aperçu (son empreinte, gardée dans la session : jamais le code)
            $session = $request->getSession();
            if ($confirm) {
                $expected = $session->get('loss_shipment_preview_code');
                $session->remove('loss_shipment_preview_code');
                $read = $this->shipmentsUseCase()->codeFingerprint($body['code']);
                if (!is_string($expected) || $read === null || !hash_equals($expected, $read)) {
                    throw new \InvalidArgumentException('Le code relu n\'est pas celui de l\'aperçu : rien n\'est réservé. Refaites l\'aperçu avec le QR de ce boîtier.');
                }
            }
            try {
                $result = $this->shipmentsUseCase()->reserveOrPreview($body + ($confirm ? ['confirm' => true] : []), (string) $this->getUser()?->getUserIdentifier());
            } catch (\App\Gestion\Services\GestionValidationException $e) {
                throw new \InvalidArgumentException('Expédition refusée : ' . implode(', ', array_map(fn ($k, $v) => "$k ($v)", array_keys($e->fields), $e->fields)) . '.', 0, $e);
            } catch (\App\Gestion\Services\GestionConflictException $e) {
                throw new \InvalidArgumentException($e->getMessage(), 0, $e);
            }
            if ($confirm) {
                $this->addFlash('success', sprintf('Boîtier %s réservé pour le site %s.', $result['shipment']['serial'], $result['shipment']['tenantCode']));
                // lot L6-c : le code est parti aux administrateurs du site (jamais gardé) ; sinon, le renvoyer en relisant l'étiquette
                $this->addFlash(($result['codeSentTo'] ?? 0) > 0 ? 'success' : 'warning', ($result['codeSentTo'] ?? 0) > 0
                    ? sprintf('Code envoyé à %d administrateur(s) du site.', $result['codeSentTo'])
                    : 'Le code n\'a pu être envoyé à aucun administrateur : utilisez « Renvoyer le code » en relisant l\'étiquette.');
            } else {
                // le code n'est jamais gardé dans la session (relecture, M2) : il se relit à la confirmation
                $request->getSession()->getFlashBag()->add('loss_shipment_preview', ['input' => array_diff_key($body, ['code' => true])] + $result['preview']);
                $session->set('loss_shipment_preview_code', $this->shipmentsUseCase()->codeFingerprint($body['code']));
            }
        });
    }

    /** Lot L6-c (B) : renvoyer le code d'une expédition réservée ou branchée, relu sur l'étiquette (il doit être celui de l'expédition) */
    #[Route('/admin/plateforme/expedition/{id}/code', name: 'admin_plateforme_loss_shipment_code', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function lossShipmentCode(int $id, Request $request): Response
    {
        return $this->action($request, function () use ($id, $request) {
            try {
                $result = $this->shipmentsUseCase()->resendCode($id, ['code' => trim((string) $request->request->get('code', ''))], (string) $this->getUser()?->getUserIdentifier());
            } catch (\App\Gestion\Services\GestionValidationException $e) {
                throw new \InvalidArgumentException(($e->fields['code'] ?? '') === 'CODE_MISMATCH' ? 'Ce code n\'est pas celui de cette expédition : rien n\'est envoyé.' : 'Code illisible.', 0, $e);
            } catch (\App\Gestion\Services\GestionConflictException $e) {
                throw new \InvalidArgumentException($e->getMessage(), 0, $e);
            }
            $this->addFlash($result['codeSentTo'] > 0 ? 'success' : 'warning', sprintf('Code renvoyé à %d administrateur(s) du site.', $result['codeSentTo']));
        });
    }

    #[Route('/admin/plateforme/expedition/{id}/annuler', name: 'admin_plateforme_loss_shipment_cancel', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function lossShipmentCancel(int $id, Request $request): Response
    {
        return $this->action($request, function () use ($id) {
            try {
                $this->shipmentsUseCase()->cancel($id, (string) $this->getUser()?->getUserIdentifier());
            } catch (\App\Gestion\Services\GestionConflictException $e) {
                throw new \InvalidArgumentException($e->getMessage(), 0, $e);
            }
            $this->addFlash('success', sprintf('Expédition n° %d annulée : son code est libéré.', $id));
        });
    }

    private function shipmentsUseCase(): \App\LossPrevention\UseCase\LossShipmentUseCase
    {
        return $this->lossShipments ?? throw new \InvalidArgumentException('Expéditions des boîtiers indisponibles.');
    }

    /** @return list<array<string, mixed>> */
    private function lossShipmentsList(): array
    {
        try {
            return $this->lossShipments?->all()['shipments'] ?? [];
        } catch (\PDOException) {
            return []; // la table n'est pas encore posée sur la base maître
        }
    }

    #[Route('/admin/plateforme/demande/{id}/{decision}', name: 'admin_plateforme_request', methods: ['POST'], requirements: ['id' => '\d+', 'decision' => 'grant|refuse'])]
    public function request(int $id, string $decision, Request $request): Response
    {
        return $this->action($request, function () use ($id, $decision, $request) {
            $result = $this->manage->decide($id, ['action' => $decision, 'source' => $request->request->get('source', 'manual'), 'days' => $request->request->get('days')], $this->getUser()?->getUserIdentifier());
            $this->addFlash('success', sprintf('Demande n° %d : %s.', $id, $result['request']['status']));
        });
    }

    private function action(Request $request, callable $do): Response
    {
        $this->denyAccessUnlessGranted(RoleAssignmentPolicy::SUPER_ADMIN);
        if (!$this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Jeton de sécurité invalide : rien n\'a été modifié.');

            return $this->redirect($this->pageUrl([]));
        }
        try {
            $do();
        } catch (PlatformValidationException $e) {
            foreach ($e->errors as $error) {
                $this->addFlash('danger', $error['path'] . ' : ' . $error['message']);
            }
        } catch (\InvalidArgumentException|\DomainException|\OutOfBoundsException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirect($this->pageUrl(array_filter(['site' => $request->request->get('site')])));
    }

    /** URL de la page habillée par EasyAdmin (/admin?routeName=…) : une redirection vers la route nue perdrait le menu et le thème */
    private function pageUrl(array $params): string
    {
        try {
            return $this->urls->unsetAll()->setRoute('admin_plateforme', $params)->generateUrl();
        } catch (\Throwable) {
            return $this->generateUrl('admin_plateforme', $params);
        }
    }
}

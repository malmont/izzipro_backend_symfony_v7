<?php

namespace App\Controller\Admin;

use App\Services\EntrepriseService\EntrepriseValidationException;
use App\Services\BoutiqueSettingsService\BoutiqueSettingsException;
use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use App\Services\ShippingService\ShippingSetupService;
use App\Services\FeatureAccess\SiteFeaturesValidationException;
use App\UseCase\SiteFeaturesUseCase\UpdateSiteFeaturesUseCase;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Page « Livraison » du back-office (livraison, lot 1, 07/11/2026) : cheminement (mode de remise, retrait, poids, gabarits, clé
 * EasyPost), réglages d'expédition écrits par le registre (UpdateSiteFeaturesUseCase : mêmes règles que PUT /api/site/features et
 * que l'assistant), transporteurs du site et liens vers les écrans. Administrateurs du site.
 */
#[IsGranted('ROLE_ADMIN')]
class ShippingConsoleAdminController extends AbstractController
{
    private const CSRF_ID = 'admin_livraison';
    private const SCREENS = [
        'carriers' => ['crud' => CarrierCrudController::class], 'easypost' => ['crud' => EasyPostConfigurationCrudController::class],
        'product_shipping' => ['crud' => ProductShippingCrudController::class], 'packaging_types' => ['crud' => PackagingTypeCrudController::class],
        'shipping_classes' => ['crud' => ShippingClassCrudController::class], 'products' => ['crud' => ProductCrudController::class], 'shipping' => ['route' => 'admin_livraison'],
        'product_weights' => ['route' => 'admin_livraison_poids'],
    ];

    private const CSRF_WEIGHTS = 'admin_livraison_poids';
    private const SESSION_PROPOSALS = 'admin_livraison_poids_proposals';

    public function __construct(
        private readonly ShippingSetupService $setup,
        private readonly UpdateSiteFeaturesUseCase $features,
        private readonly TenantCurrencyProvider $currency,
        private readonly AdminUrlGenerator $urls,
        private readonly \App\Services\ShippingService\ProductDimensionsService $dimensions,
        private readonly \App\Services\ShippingService\ShippingDimensionsEstimator $estimator,
        private readonly ?\App\Services\MediaUrlResolver $media = null,
    ) {
    }

    /**
     * Saisie rapide des poids et dimensions (lot 1 bis, 07/11/2026) : grille des produits à expédier (sans poids par défaut, `?tous=1` pour
     * tous), enregistrement en un envoi, estimation par l'IA qui préremplit la grille (rien n'est enregistré sans confirmation).
     */
    #[Route('/admin/livraison/poids', name: 'admin_livraison_poids', methods: ['GET', 'POST'])]
    public function weights(Request $request): Response
    {
        $all = $request->query->getBoolean('tous') || $request->request->getBoolean('tous');
        $self = fn () => $this->pageUrl('admin_livraison_poids', $all ? ['tous' => 1] : []);
        $proposals = [];
        $error = null;
        $result = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid(self::CSRF_WEIGHTS, (string) $request->request->get('_token'))) {
                $this->addFlash('danger', 'Jeton de sécurité invalide : rechargez la page.');

                return $this->redirect($self());
            }
            if ($request->request->get('action') === 'estimate') {
                try {
                    $rows = $this->dimensions->rows(!$all, $this->publicHost($request), \App\Services\ShippingService\ShippingDimensionsEstimator::MAX_PRODUCTS);
                    $estimated = $this->estimator->estimate($rows, $this->dimensions->classes(), $this->getUser()?->getUserIdentifier(), 'fr', !$this->isGranted('ROLE_SUPER_ADMIN'));
                    foreach ($estimated['proposals'] as $pr) {
                        $proposals[$pr['productId']] = $pr;
                    }
                    // Propositions gardées en session le temps de la redirection habillée (jamais écrites en base ici)
                    $request->getSession()->set(self::SESSION_PROPOSALS, $proposals);
                    $this->addFlash('info', sprintf('%d proposition(s) de l\'IA préremplies ci-dessous : vérifiez, corrigez, puis enregistrez. Rien n\'est encore enregistré.', count($proposals)));
                } catch (\App\Services\LandingAiService\LandingAiException $e) {
                    $this->addFlash('danger', $e->getMessage());
                }

                return $this->redirect($self());
            } else {
                $rows = [];
                foreach ((array) $request->request->all('rows') as $productId => $r) {
                    if (!is_array($r)) {
                        continue;
                    }
                    $rows[] = ['productId' => (int) $productId, 'weightKg' => $r['weightKg'] ?? null, 'lengthCm' => $r['lengthCm'] ?? null, 'widthCm' => $r['widthCm'] ?? null, 'heightCm' => $r['heightCm'] ?? null, 'shippingClassId' => $r['shippingClassId'] ?? null];
                }
                $result = $this->dimensions->save($rows, 'grid');
                if ($result['errors'] === []) {
                    $this->addFlash('success', sprintf('%d fiche(s) poids enregistrée(s)%s.', $result['saved'], $result['skipped'] > 0 ? sprintf(', %d ligne(s) sans poids laissée(s) telle(s) quelle(s)', $result['skipped']) : ''));
                } else {
                    $this->addFlash('danger', 'Rien n\'a été enregistré : ' . implode(' ; ', array_map(fn ($e) => $e['path'] . ' : ' . $e['message'], $result['errors'])));
                }

                return $this->redirect($self());
            }
        }
        if ($request->hasSession() && $request->getSession()->has(self::SESSION_PROPOSALS)) {
            $proposals = (array) $request->getSession()->get(self::SESSION_PROPOSALS);
            $request->getSession()->remove(self::SESSION_PROPOSALS);
        }
        $rows = $this->dimensions->rows(!$all, $this->publicHost($request));

        return $this->render('admin/livraison/poids.html.twig', [
            'rows' => $rows, 'classes' => $this->dimensions->classes(), 'proposals' => $proposals, 'all' => $all, 'error' => $error,
            'csrf_id' => self::CSRF_WEIGHTS, 'maxEstimate' => \App\Services\ShippingService\ShippingDimensionsEstimator::MAX_PRODUCTS,
            'backUrl' => $this->pageUrl('admin_livraison'), 'gridUrl' => $this->pageUrl('admin_livraison_poids'), 'gridAllUrl' => $this->pageUrl('admin_livraison_poids', ['tous' => 1]),
        ]);
    }

    private function publicHost(Request $request): string
    {
        return $this->media?->getPublicHost($request->getSchemeAndHttpHost()) ?? $request->getSchemeAndHttpHost();
    }

    #[Route('/admin/livraison', name: 'admin_livraison', methods: ['GET'])]
    public function index(): Response
    {
        $overview = $this->setup->overview();
        foreach ($overview['steps'] as $i => $step) {
            $overview['steps'][$i]['url'] = $this->screenUrl($step['screen']);
        }
        $screens = [];
        foreach (array_keys(self::SCREENS) as $key) {
            $screens[$key] = $this->screenUrl($key);
        }

        return $this->render('admin/livraison/index.html.twig', ['overview' => $overview, 'currency' => $this->currency->code(), 'screens' => $screens, 'csrf_id' => self::CSRF_ID]);
    }

    #[Route('/admin/livraison/regler', name: 'admin_livraison_regler', methods: ['POST'])]
    public function settle(Request $request): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Jeton de sécurité invalide : rechargez la page.');

            return $this->redirect($this->pageUrl('admin_livraison'));
        }
        $free = trim((string) $request->request->get('free_above', ''));
        $weight = str_replace(',', '.', trim((string) $request->request->get('default_weight_kg', '')));
        $values = [
            'shipping.mode' => (string) $request->request->get('mode', 'none'),
            'shipping.easypost_environment' => (string) $request->request->get('easypost_environment', 'test'),
            'shipping.default_weight_kg' => is_numeric($weight) ? (float) $weight : $weight,
            'shipping.free_above' => $free === '' ? null : (is_numeric($free) ? (int) round((float) $free * 100) : $free),
        ];
        try {
            $this->features->execute($values, $request->getSchemeAndHttpHost(), 'fr');
            $this->addFlash('success', 'Réglages de livraison enregistrés.');
        } catch (SiteFeaturesValidationException|EntrepriseValidationException|BoutiqueSettingsException $e) {
            $errors = $e->errors ?? [];
            $this->addFlash('danger', 'Réglages refusés : ' . ($errors !== [] ? implode(' ; ', array_map(fn ($er) => ($er['path'] ?? '') . ' : ' . ($er['message'] ?? ''), $errors)) : $e->getMessage()));
        }

        return $this->redirect($this->pageUrl('admin_livraison'));
    }

    /** URL habillée EasyAdmin d'une page de ce contrôleur (sinon page blanche après redirection) */
    private function pageUrl(string $route, array $params = []): string
    {
        try {
            return $this->urls->unsetAll()->setRoute($route, $params)->generateUrl();
        } catch (\Throwable) {
            return $this->generateUrl($route, $params);
        }
    }

    private function screenUrl(?string $key): ?string
    {
        $screen = self::SCREENS[$key ?? ''] ?? null;
        if ($screen === null) {
            return null;
        }
        try {
            return isset($screen['crud']) ? $this->urls->unsetAll()->setController($screen['crud'])->setAction('index')->generateUrl() : $this->urls->unsetAll()->setRoute($screen['route'])->generateUrl();
        } catch (\Throwable) {
            return null;
        }
    }
}

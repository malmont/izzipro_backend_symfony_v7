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
    ];

    public function __construct(private readonly ShippingSetupService $setup, private readonly UpdateSiteFeaturesUseCase $features, private readonly TenantCurrencyProvider $currency, private readonly AdminUrlGenerator $urls)
    {
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

            return $this->redirectToRoute('admin_livraison');
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

        return $this->redirectToRoute('admin_livraison');
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

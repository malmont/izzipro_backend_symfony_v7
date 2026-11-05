<?php

namespace App\Controller\Admin;

use App\Controller\Admin\Memoires\AiQuotaPageController;
use App\Services\BoutiqueSettingsService\BoutiqueSettingsException;
use App\Services\EntrepriseService\EntrepriseForbiddenException;
use App\Services\EntrepriseService\EntrepriseValidationException;
use App\Services\FeatureAccess\FeatureCatalog;
use App\Services\FeatureAccess\SiteFeatureStateService;
use App\Services\FeatureAccess\SiteFeaturesValidationException;
use App\Services\Platform\PlatformModeService;
use App\Services\Platform\SiteReadinessService;
use App\Services\Platform\CustomDomainService;
use App\Services\TenantConnectionManager;
use App\Services\LandingAiService\LandingAiException;
use App\UseCase\SiteAssistantUseCase\ChatWithSiteAssistantUseCase;
use App\UseCase\SiteAssistantUseCase\ConfirmSiteAssistantActionUseCase;
use App\UseCase\SiteAssistantUseCase\SiteAssistantValidationException;
use Symfony\Component\HttpFoundation\JsonResponse;
use App\UseCase\SiteFeaturesUseCase\RequestSiteFeatureUseCase;
use App\UseCase\SiteFeaturesUseCase\SiteFeatureRequestException;
use App\UseCase\SiteFeaturesUseCase\UpdateSiteFeaturesUseCase;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Onglet « Mon site » (console de pilotage, lot 5, 04/11/2026), administrateur du site : le registre (SiteFeatureStateService) en
 * sections, avec pour chaque entrée sa valeur, son état et ses prérequis ; formulaires Localisation et Commerce (mêmes écritures que
 * PUT /api/site/features, par UpdateSiteFeaturesUseCase), modules et applications avec leur droit d'usage et un bouton « demander »,
 * services avec lien vers leur écran. Habillage EasyAdmin depuis le menu (/admin?routeName=admin_mon_site).
 */
class SiteConsoleAdminController extends AbstractController
{
    private const CSRF_ID = 'admin_mon_site';
    /** clé `href` du registre → écran EasyAdmin (CRUD ou route) */
    private const SCREENS = [
        'entreprise' => ['crud' => EntrepriseCrudController::class], 'app_keys' => ['route' => 'admin_app_keys'], 'boutique_settings' => ['crud' => BoutiqueSettingCrudController::class],
        'subscription_plans' => ['crud' => SubscriptionPlanCrudController::class], 'taxes' => ['crud' => TaxCrudController::class], 'stripe_config' => ['crud' => StripeConfigCrudController::class],
        'pos_stripe' => ['route' => 'admin_pos_stripe'], 'easypost' => ['crud' => EasyPostConfigurationCrudController::class], 'google_places' => ['crud' => GooglePlacesConfigCrudController::class],
        'email_configuration' => ['crud' => EmailConfigurationCrudController::class], 'ai_credits' => ['crud' => AiCreditSettingCrudController::class], 'mv_ai_quotas' => ['route' => 'admin_mv_ai_quotas'],
        'review_settings' => ['crud' => ReviewSettingCrudController::class],
        'carriers' => ['crud' => CarrierCrudController::class], 'products' => ['crud' => ProductCrudController::class], 'cash_registers' => ['crud' => CashRegisterCrudController::class],
        'esg_questions' => ['crud' => \App\Controller\Admin\ESG\DiagnosticQuestionCrudController::class], 'short_code' => ['route' => 'admin_short_code'],
    ];
    /** clé d'un prérequis → écran où le satisfaire */
    private const PREREQ_SCREENS = ['app.key' => 'app_keys', 'stripe.online' => 'stripe_config', 'subscription_plan.active' => 'subscription_plans', 'carrier.fixed_price' => 'carriers', 'tax.table' => 'taxes', 'entreprise.address' => 'entreprise', 'module.pos' => 'app_keys'];

    public function __construct(
        private readonly SiteFeatureStateService $state,
        private readonly UpdateSiteFeaturesUseCase $update,
        private readonly RequestSiteFeatureUseCase $requestFeature,
        private readonly PlatformModeService $platform,
        private readonly AdminUrlGenerator $urls,
        private readonly SiteReadinessService $readiness,
        private readonly ChatWithSiteAssistantUseCase $assistantChat,
        private readonly ConfirmSiteAssistantActionUseCase $assistantConfirm,
        private readonly CustomDomainService $domains,
        private readonly TenantConnectionManager $tenants,
    ) {
    }

    #[Route('/admin/mon-site', name: 'admin_mon_site', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $groups = ['localisation' => [], 'modules' => [], 'apps' => [], 'commerce' => [], 'services' => []];
        foreach ($this->state->all() as $entry) {
            $entry['screenUrl'] = $this->screenUrl($entry['href'] ?? null);
            $entry['description'] = FeatureCatalog::DESCRIPTIONS[$entry['key']] ?? null;
            $entry['howTo'] = FeatureCatalog::HOW_TO[$entry['key']] ?? null;
            foreach ($entry['prerequisites'] as $i => $p) {
                $entry['prerequisites'][$i]['url'] = $p['ok'] ? null : $this->screenUrl(self::PREREQ_SCREENS[$p['key']] ?? null);
            }
            if ($entry['scope'] === FeatureCatalog::SCOPE_PLATFORM && !$entry['value'] && ($entry['request'] ?? null) !== 'pending') {
                $entry['trial'] = $this->requestFeature->trialOffer($entry['key']);
            }
            $groups[$entry['group']][] = $entry;
        }

        $readiness = $this->readiness->forCurrentSite();
        foreach ($readiness['steps'] as $i => $step) {
            $readiness['steps'][$i]['url'] = $this->stepUrl($step);
        }
        if ($readiness['next'] !== null) {
            $readiness['next']['url'] = $this->stepUrl($readiness['next']);
        }

        $domain = $this->domains->status((string) $this->tenants->getCurrentTenantCode());

        return $this->render('admin/mon_site/index.html.twig', [
            'domain' => $domain,
            'readiness' => $readiness,
            'groups' => $groups,
            'platformMode' => $this->platform->mode(),
            'shopUrl' => $this->platform->shopUrl(),
            'superAdmin' => $this->isGranted('ROLE_SUPER_ADMIN'),
            'timezones' => \DateTimeZone::listIdentifiers(),
            'currencies' => \App\Services\BoutiqueSettingsService\SiteCurrencyService::SUPPORTED,
            'appKeysUrl' => $this->screenUrl('app_keys'),
            'csrf_id' => self::CSRF_ID,
        ]);
    }

    /** Formulaires Localisation et Commerce : les champs envoyés deviennent des valeurs du registre (cases absentes = faux) */
    #[Route('/admin/mon-site/regler', name: 'admin_mon_site_regler', methods: ['POST'])]
    public function settle(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        if (!$this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Jeton de sécurité invalide : rien n\'a été modifié.');

            return $this->redirect($this->pageUrl());
        }
        $values = [];
        $section = (string) $request->request->get('section', '');
        if ($section === 'localisation') {
            $values['site.locale'] = (string) $request->request->get('site_locale', '') ?: null;
            $values['site.timezone'] = (string) $request->request->get('site_timezone', '') ?: null;
            $values['site.currency'] = (string) $request->request->get('site_currency', 'CAD');
            $values['site.prices_include_tax'] = $request->request->getBoolean('site_prices_include_tax');
        } elseif ($section === 'commerce') {
            $modes = $request->request->all('commerce_sales_modes');
            $values['commerce.sales_modes'] = is_array($modes) && $modes !== [] ? array_values($modes) : null;
            $values['commerce.guest_checkout'] = $request->request->getBoolean('commerce_guest_checkout');
            $values['commerce.subscriptions'] = $request->request->getBoolean('commerce_subscriptions');
            $values['commerce.tax_provider'] = (string) $request->request->get('commerce_tax_provider', 'table');
        } else {
            $this->addFlash('danger', 'Section inconnue.');

            return $this->redirect($this->pageUrl());
        }
        try {
            $this->update->execute($values, $request->getSchemeAndHttpHost(), 'fr');
            $this->addFlash('success', $section === 'localisation' ? 'Localisation du site enregistrée.' : 'Réglages du commerce enregistrés.');
        } catch (SiteFeaturesValidationException|EntrepriseValidationException|BoutiqueSettingsException $e) {
            $errors = $e instanceof SiteFeaturesValidationException ? $e->errors : ($e instanceof EntrepriseValidationException ? $e->errors : $e->errors);
            foreach ($errors as $error) {
                $this->addFlash('danger', FeatureCatalog::keyForStorage($error['path']) . ' : ' . $error['message']);
            }
        } catch (EntrepriseForbiddenException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirect($this->pageUrl());
    }

    /** Domaine propre : enregistrer, vérifier ou retirer (session EasyAdmin, CSRF) ; même service que /api/site/domain */
    #[Route('/admin/mon-site/domaine', name: 'admin_mon_site_domaine', methods: ['POST'])]
    public function domain(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        if (!$this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Jeton de sécurité invalide : rien n\'a été modifié.');

            return $this->redirect($this->pageUrl() . '#domaine');
        }
        $code = (string) $this->tenants->getCurrentTenantCode();
        $by = $this->getUser()?->getUserIdentifier();
        try {
            switch ((string) $request->request->get('action', 'check')) {
                case 'save':
                    $status = $this->domains->set($code, (string) $request->request->get('domain', ''), $by);
                    $this->addFlash('success', sprintf('Domaine %s enregistré dans l\'annuaire de la plateforme. Créez maintenant les enregistrements DNS chez votre registraire, puis vérifiez.', $status['domain']));
                    break;
                case 'remove':
                    $this->domains->remove($code, $by);
                    $this->addFlash('success', 'Domaine propre retiré : le site répond sur son adresse de la plateforme.');
                    break;
                default:
                    $status = $this->domains->status($code, true, true); // vérification fraîche
                    $this->addFlash($status['state'] === 'live' ? 'success' : 'warning', match ($status['state']) {
                        'live' => 'Votre domaine est en ligne en HTTPS.',
                        'dns_ok' => 'DNS en place ; HTTPS pas encore joignable : le certificat s\'émet automatiquement, réessayez dans quelques minutes.',
                        'dns_pending' => sprintf('Le DNS ne pointe pas encore vers le serveur (trouvé : %s ; attendu : %s). Vérifiez vos enregistrements et la propagation.', implode(', ', $status['checks']['dns']['found']) ?: 'rien', implode(', ', $status['expected']['ips'])),
                        default => 'Aucun domaine enregistré.',
                    });
            }
        } catch (\InvalidArgumentException|\DomainException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirect($this->pageUrl() . '#domaine');
    }

    /** Assistant IA dans la page (session EasyAdmin, jeton CSRF en en-tête X-CSRF-Token) : même cas d'usage que POST /api/site/assistant */
    #[Route('/admin/mon-site/assistant', name: 'admin_mon_site_assistant', methods: ['POST'])]
    public function assistant(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        if (!$this->isCsrfTokenValid(self::CSRF_ID, (string) $request->headers->get('X-CSRF-Token'))) {
            return $this->json(['error' => 'CSRF', 'message' => 'Jeton de sécurité invalide : rechargez la page.'], 403);
        }
        $body = json_decode($request->getContent(), true);
        try {
            $result = $this->assistantChat->execute(is_array($body) ? $body : [], $this->getUser()?->getUserIdentifier());
        } catch (SiteAssistantValidationException $e) {
            return $this->json(['error' => 'VALIDATION_FAILED', 'message' => $e->getMessage(), 'errors' => $e->errors], 422);
        } catch (LandingAiException $e) {
            return $this->json(['error' => 'AI', 'message' => $e->getMessage()], $e->getStatusCode());
        }
        foreach ($result['links'] as $i => $link) {
            $result['links'][$i]['url'] = $this->screenUrl($link['screen']);
        }

        return $this->json($result);
    }

    #[Route('/admin/mon-site/assistant/actions/{id}/confirm', name: 'admin_mon_site_assistant_confirm', methods: ['POST'], requirements: ['id' => '[a-f0-9]{16}'])]
    public function assistantConfirm(string $id, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        if (!$this->isCsrfTokenValid(self::CSRF_ID, (string) $request->headers->get('X-CSRF-Token'))) {
            return $this->json(['error' => 'CSRF', 'message' => 'Jeton de sécurité invalide : rechargez la page.'], 403);
        }
        try {
            $result = $this->assistantConfirm->execute($id, $request->getSchemeAndHttpHost(), $this->getUser()?->getUserIdentifier());
        } catch (\OutOfBoundsException $e) {
            return $this->json(['error' => 'ACTION_NOT_FOUND', 'message' => $e->getMessage()], 404);
        } catch (SiteFeaturesValidationException|EntrepriseValidationException|BoutiqueSettingsException $e) {
            return $this->json(['error' => 'VALIDATION_FAILED', 'message' => $e->getMessage(), 'errors' => array_map(fn (array $err) => ['path' => FeatureCatalog::keyForStorage($err['path']), 'message' => $err['message']], $e->errors ?? [])], 422);
        } catch (EntrepriseForbiddenException|\InvalidArgumentException|SiteFeatureRequestException|\DomainException $e) {
            return $this->json(['error' => 'REFUSED', 'message' => $e->getMessage()], 409);
        }

        return $this->json($result);
    }

    /** Page de forfait : la boutique de la plateforme si son adresse est réglée, sinon une page d'attente (le bouton existe dès maintenant, 05/11/2026) */
    #[Route('/admin/mon-site/forfait', name: 'admin_mon_site_forfait', methods: ['GET'])]
    public function plan(): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $shop = $this->platform->shopUrl();
        if ($shop !== null) {
            return $this->redirect($shop);
        }

        return $this->render('admin/mon_site/forfait.html.twig', ['monSiteUrl' => $this->pageUrl()]);
    }

    #[Route('/admin/mon-site/demander/{key}', name: 'admin_mon_site_demander', methods: ['POST'], requirements: ['key' => '[a-z_]+\.[a-z_]+'])]
    public function ask(string $key, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        if (!$this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Jeton de sécurité invalide : rien n\'a été modifié.');

            return $this->redirect($this->pageUrl());
        }
        try {
            $outcome = $this->requestFeature->execute($key, (string) $request->request->get('message', ''), $this->getUser()?->getUserIdentifier());
            $this->addFlash('success', $outcome['kind'] === 'trial'
                ? sprintf('Essai gratuit de %s ouvert jusqu\'au %s : c\'est activé, suivez le cheminement pour le configurer.', FeatureCatalog::label($key), substr((string) ($outcome['entitlement']['ends_at'] ?? ''), 0, 10))
                : sprintf('Demande envoyée pour %s : la plateforme vous répond par courriel.', FeatureCatalog::label($key)));
        } catch (\InvalidArgumentException|SiteFeatureRequestException $e) {
            $this->addFlash('warning', $e->getMessage());
        }

        return $this->redirect($this->pageUrl());
    }

    /** Où faire une étape du cheminement : écran EasyAdmin, éditeur du site, boutique de la plateforme ou cette page (demande, registre) */
    private function stepUrl(array $step): ?string
    {
        $action = $step['action'];

        return match ($action['type']) {
            'domain' => $this->pageUrl() . '#domaine',
            'screen' => $this->screenUrl($action['screen'] ?? null),
            'editor' => $action['url'] ?? null,
            'shop' => $action['url'] ?? null,
            'registry' => $this->pageUrl() . '#localisation',
            default => null,
        };
    }

    /** URL de la page habillée par EasyAdmin : une redirection vers la route nue perdrait le menu et le thème */
    private function pageUrl(): string
    {
        try {
            return $this->urls->unsetAll()->setRoute('admin_mon_site')->generateUrl();
        } catch (\Throwable) {
            return $this->generateUrl('admin_mon_site');
        }
    }

    private function screenUrl(?string $href): ?string
    {
        $screen = self::SCREENS[$href ?? ''] ?? null;
        if ($screen === null) {
            return null;
        }
        try {
            if (isset($screen['crud'])) {
                return $this->urls->unsetAll()->setController($screen['crud'])->setAction('index')->generateUrl();
            }

            return $this->urls->unsetAll()->setRoute($screen['route'])->generateUrl();
        } catch (\Throwable) {
            return null;
        }
    }
}

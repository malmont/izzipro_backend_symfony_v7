<?php

namespace App\Controller\Admin;

use App\Services\MediaUrlResolver;
use App\Services\Platform\SiteConfigurationPathsService;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Page « Configuration » (lot 5, 07/11/2026) : cheminements des parties complexes à régler (paiements Stripe, taxes, courriels, avis
 * clients), chacun avec ses étapes et l'écran où agir ; une seule action, le courriel d'essai à l'administrateur connecté.
 * Administrateurs du site ; habillage EasyAdmin.
 */
#[IsGranted('ROLE_ADMIN')]
class ConfigurationConsoleAdminController extends AbstractController
{
    private const CSRF_EMAIL_TEST = 'admin_configuration_email_test';
    private const SCREENS = [
        'stripe_config' => ['crud' => StripeConfigCrudController::class], 'taxes' => ['crud' => TaxCrudController::class], 'entreprise' => ['crud' => EntrepriseCrudController::class],
        'address_entreprise' => ['crud' => AddressEntrepriseCrudController::class], 'mon_site' => ['route' => 'admin_mon_site'], 'email_configuration' => ['crud' => EmailConfigurationCrudController::class],
        'review_settings' => ['crud' => ReviewSettingCrudController::class], 'reviews' => ['crud' => ReviewCrudController::class], 'google_places' => ['crud' => GooglePlacesConfigCrudController::class],
    ];

    public function __construct(private readonly SiteConfigurationPathsService $paths, private readonly AdminUrlGenerator $urls, private readonly ?MediaUrlResolver $media = null)
    {
    }

    #[Route('/admin/configuration', name: 'admin_configuration', methods: ['GET'])]
    public function index(): Response
    {
        $overview = $this->paths->overview();
        foreach ($overview as $key => $path) {
            foreach ($path['steps'] as $i => $step) {
                $overview[$key]['steps'][$i]['url'] = $this->screenUrl($step['screen']);
            }
        }

        return $this->render('admin/configuration/index.html.twig', ['overview' => $overview, 'csrf_id' => self::CSRF_EMAIL_TEST, 'adminEmail' => (string) ($this->getUser()?->getEmail() ?? '')]);
    }

    #[Route('/admin/configuration/courriel-essai', name: 'admin_configuration_email_test', methods: ['POST'])]
    public function emailTest(Request $request): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF_EMAIL_TEST, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Formulaire expiré : réessayez.');

            return $this->redirect($this->pageUrl());
        }
        $to = (string) ($this->getUser()?->getEmail() ?? '');
        if ($to === '') {
            $this->addFlash('danger', 'Votre compte n\'a pas d\'adresse de courriel.');

            return $this->redirect($this->pageUrl());
        }
        $host = $this->media?->getPublicHost($request->getSchemeAndHttpHost()) ?? $request->getSchemeAndHttpHost();
        $via = $this->paths->sendTestEmail($to, $host);
        if ($via === null) {
            $this->addFlash('danger', 'Le courriel d\'essai n\'est pas parti : vérifiez l\'expéditeur et, si vous en avez un, votre serveur SMTP (le journal du serveur a le détail).');
        } else {
            $this->addFlash('success', sprintf('Courriel d\'essai envoyé à %s par %s. Vérifiez votre boîte de réception et vos indésirables.', $to, $via === 'site' ? 'votre serveur SMTP' : 'le serveur de la plateforme'));
        }

        return $this->redirect($this->pageUrl() . '#email');
    }

    private function pageUrl(): string
    {
        try {
            return $this->urls->unsetAll()->setRoute('admin_configuration')->generateUrl();
        } catch (\Throwable) {
            return $this->generateUrl('admin_configuration');
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

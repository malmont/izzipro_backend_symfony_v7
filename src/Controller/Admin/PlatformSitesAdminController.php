<?php

namespace App\Controller\Admin;

use App\Security\RoleAssignmentPolicy;
use App\Services\Agency\AgencyEntitlements;
use App\Services\Agency\AgencyException;
use App\Services\FeatureAccess\FeatureCatalog;
use App\Services\Platform\Sites\PlatformSiteException;
use App\Services\Platform\Sites\PlatformSiteService;
use App\UseCase\PlatformUseCase\PlatformValidationException;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Page « Plateforme : sites » (lot TYPE-1, 16/11/2026, `docs/agences-et-import.md` § 9), super administrateur : les sites de la
 * plateforme avec leurs pastilles calculées, la création d'un site avec son type (normal ou agence), et les demandes de création
 * avec leurs étapes (« Reprendre », « Abandonner »). Chaque action POST refait le contrôle du rôle et du jeton CSRF, puis passe par le
 * même service que l'API (`/api/platform/sites`). Habillage EasyAdmin depuis le menu.
 */
class PlatformSitesAdminController extends AbstractController
{
    private const CSRF_ID = 'admin_plateforme_sites';

    public function __construct(private readonly PlatformSiteService $sites, private readonly AdminUrlGenerator $urls)
    {
    }

    #[Route('/admin/plateforme/sites', name: 'admin_plateforme_sites', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyAccessUnlessGranted(RoleAssignmentPolicy::SUPER_ADMIN);
        $badge = $request->query->get('pastille') ?? $request->attributes->get('pastille');
        $badge = is_string($badge) && in_array($badge, PlatformSiteService::BADGES, true) ? $badge : null;
        $modules = [];
        foreach (AgencyEntitlements::features() as $key) {
            $modules[$key] = FeatureCatalog::label($key);
        }

        return $this->render('admin/plateforme/sites.html.twig', [
            'csrf_id' => self::CSRF_ID, 'sites' => $this->sites->sites($badge), 'badge' => $badge, 'badges' => PlatformSiteService::BADGES, 'requests' => $this->sites->all(30),
            'modules' => $modules, 'defaults' => PlatformSiteService::DEFAULT_MODULES,
        ]);
    }

    #[Route('/admin/plateforme/sites/creer', name: 'admin_plateforme_sites_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        return $this->action($request, function () use ($request): void {
            $form = $request->request;
            $number = static fn (string $key): ?int => is_numeric($form->get($key)) ? (int) $form->get($key) : null;
            $data = ['type' => (string) $form->get('type', PlatformSiteService::NORMAL), 'code' => $form->get('code'), 'name' => $form->get('name'), 'adminEmail' => $form->get('adminEmail'),
                'adminFirstName' => (string) $form->get('adminFirstName', ''), 'adminLastName' => (string) $form->get('adminLastName', ''), 'locale' => $form->get('locale') ?: null,
                'modules' => array_values(array_filter($form->all('modules'), 'is_string'))];
            if ($data['type'] === PlatformSiteService::AGENCY) {
                $data['agency'] = array_filter(['name' => (string) $form->get('agencyName', ''), 'contactEmail' => $form->get('agencyContactEmail') ?: null, 'isTest' => $form->getBoolean('agencyTest'),
                    'managerRole' => $form->getBoolean('agencyManagerRole'), 'trialDays' => $number('agencyTrialDays'), 'maxSites' => $number('agencyMaxSites')], static fn (mixed $v): bool => $v !== null);
            }
            $made = $this->sites->request($data, $this->getUser()?->getUserIdentifier());
            // EasyAdmin affiche ses messages sans échappement : tout ce qui vient d'une saisie est échappé ici
            $this->addFlash('success', sprintf('Demande n° %d enregistrée : le site « %s » se fabrique en tâche de fond. Son avancement se lit plus bas.', $made['id'], htmlspecialchars((string) $made['siteCode'], ENT_QUOTES)));
        });
    }

    #[Route('/admin/plateforme/sites/demandes/{id}/{geste}', name: 'admin_plateforme_sites_request', methods: ['POST'], requirements: ['id' => '\d+', 'geste' => 'reprendre|abandonner'])]
    public function gesture(int $id, string $geste, Request $request): Response
    {
        return $this->action($request, function () use ($id, $geste): void {
            $by = $this->getUser()?->getUserIdentifier();
            if ($geste === 'reprendre') {
                $this->sites->resume($id, $by);
                $this->addFlash('success', sprintf('Demande n° %d reprise : ses étapes manquantes sont rejouées.', $id));
            } else {
                $made = $this->sites->abandon($id, $by);
                $this->addFlash('success', sprintf('Demande n° %d abandonnée.%s', $id, $made['site'] !== null ? ' Le site « ' . htmlspecialchars((string) $made['site']['code'], ENT_QUOTES) . ' » existe : il reste un site normal.' : ' Aucun site n\'avait été créé.'));
            }
        });
    }

    private function action(Request $request, callable $do): Response
    {
        $this->denyAccessUnlessGranted(RoleAssignmentPolicy::SUPER_ADMIN);
        if (!$this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Jeton de sécurité invalide : rien n\'a été modifié.');

            return $this->redirect($this->pageUrl());
        }
        try {
            $do();
        } catch (PlatformValidationException $e) {
            foreach ($e->errors as $error) {
                $this->addFlash('danger', htmlspecialchars($error['path'] . ' : ' . $error['message'], ENT_QUOTES));
            }
        } catch (PlatformSiteException | AgencyException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirect($this->pageUrl());
    }

    private function pageUrl(): string
    {
        try {
            return $this->urls->unsetAll()->setRoute('admin_plateforme_sites')->generateUrl();
        } catch (\Throwable) {
            return $this->generateUrl('admin_plateforme_sites');
        }
    }
}

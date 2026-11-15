<?php

namespace App\Controller\Admin;

use App\Security\RoleAssignmentPolicy;
use App\Services\Agency\AgencyException;
use App\Services\Agency\AgencyMemberService;
use App\Services\Agency\AgencyService;
use App\Services\Platform\PlatformConsoleService;
use App\UseCase\PlatformUseCase\PlatformValidationException;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Page « Plateforme : agences » (lot AG-1, 15/11/2026, `docs/agences-et-import.md` § 3), super administrateur : les agences, en créer
 * une avec son site d'attache, rattacher ou détacher un site, suspendre ou reprendre, donner ou retirer un rôle d'agence, lire le
 * journal. Chaque action POST refait le contrôle du rôle et du jeton CSRF, puis passe par les mêmes services que l'API
 * (`/api/platform/agencies`). Habillage EasyAdmin depuis le menu (/admin?routeName=admin_plateforme_agences).
 */
class PlatformAgenciesAdminController extends AbstractController
{
    private const CSRF_ID = 'admin_plateforme_agences';

    public function __construct(
        private readonly AgencyService $agencies,
        private readonly AgencyMemberService $members,
        private readonly PlatformConsoleService $console,
        private readonly AdminUrlGenerator $urls,
        private readonly \App\Services\Agency\AgencySiteRequestService $requests,
    ) {
    }

    #[Route('/admin/plateforme/agences', name: 'admin_plateforme_agences', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyAccessUnlessGranted(RoleAssignmentPolicy::SUPER_ADMIN);
        $id = (int) ($request->query->get('agence') ?? $request->attributes->get('agence') ?? 0); // paramètre d'une route ouverte depuis le menu : dans les attributs
        $detail = null;
        if ($id > 0) {
            try {
                $detail = $this->agencies->show($id) + ['members' => $this->members->members($this->agencies->agency($id)), 'requests' => $this->requests->requestsOf($this->agencies->agency($id))];
            } catch (AgencyException) {
                $detail = null; // agence inconnue : la liste seule
            }
        }

        return $this->render('admin/plateforme/agences.html.twig', [
            'csrf_id' => self::CSRF_ID, 'agencies' => $this->agencies->all(), 'detail' => $detail, 'sites' => $this->console->tenantCodes(), 'reserved' => AgencyService::RESERVED_SITES,
        ]);
    }

    #[Route('/admin/plateforme/agences/creer', name: 'admin_plateforme_agences_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        return $this->action($request, function () use ($request): ?int {
            $agency = $this->agencies->create(['code' => $request->request->get('code'), 'name' => $request->request->get('name'), 'homeTenantCode' => $request->request->get('homeTenantCode'),
                'contactEmail' => $request->request->get('contactEmail'), 'test' => $request->request->getBoolean('test')], $this->getUser()?->getUserIdentifier());
            // EasyAdmin affiche ses messages sans échappement : tout ce qui vient d'une saisie est échappé ici
            $this->addFlash('success', sprintf('Agence « %s » créée, en essai jusqu\'au %s.', htmlspecialchars((string) $agency['name'], ENT_QUOTES), substr((string) $agency['trialEndsAt'], 0, 10)));

            return (int) $agency['id'];
        });
    }

    #[Route('/admin/plateforme/agences/{id}/rattacher', name: 'admin_plateforme_agences_attach', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function attach(int $id, Request $request): Response
    {
        return $this->action($request, function () use ($id, $request): int {
            $this->agencies->attach($id, $request->request->get('tenantCode'), $this->getUser()?->getUserIdentifier());
            $this->addFlash('success', sprintf('Site « %s » rattaché.', htmlspecialchars((string) $request->request->get('tenantCode'), ENT_QUOTES)));

            return $id;
        });
    }

    #[Route('/admin/plateforme/agences/{id}/detacher', name: 'admin_plateforme_agences_detach', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function detach(int $id, Request $request): Response
    {
        return $this->action($request, function () use ($id, $request): int {
            $this->agencies->detach($id, (string) $request->request->get('tenantCode'), $this->getUser()?->getUserIdentifier());
            $this->addFlash('success', sprintf('Site « %s » détaché.', htmlspecialchars((string) $request->request->get('tenantCode'), ENT_QUOTES)));

            return $id;
        });
    }

    #[Route('/admin/plateforme/agences/{id}/etat/{to}', name: 'admin_plateforme_agences_state', methods: ['POST'], requirements: ['id' => '\d+', 'to' => 'suspend|resume'])]
    public function state(int $id, string $to, Request $request): Response
    {
        return $this->action($request, function () use ($id, $to, $request): int {
            $agency = $to === 'suspend' ? $this->agencies->suspend($id, $this->getUser()?->getUserIdentifier(), $request->request->get('reason')) : $this->agencies->resume($id, $this->getUser()?->getUserIdentifier());
            $this->addFlash('success', sprintf('Agence « %s » : %s.', htmlspecialchars((string) $agency['name'], ENT_QUOTES), $agency['status']));

            return $id;
        });
    }

    /** Lot AG-2 : nombre de sites (vide = illimité) et demandes de création par jour */
    #[Route('/admin/plateforme/agences/{id}/limites', name: 'admin_plateforme_agences_limits', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function limits(int $id, Request $request): Response
    {
        return $this->action($request, function () use ($id, $request): int {
            $max = trim((string) $request->request->get('maxSites', ''));
            $daily = trim((string) $request->request->get('dailySiteRequests', ''));
            $agency = $this->agencies->setLimits($id, ['maxSites' => $max === '' ? null : (ctype_digit($max) ? (int) $max : $max), 'dailySiteRequests' => ctype_digit($daily) ? (int) $daily : $daily], $this->getUser()?->getUserIdentifier());
            $this->addFlash('success', sprintf('Limites réglées : %s site(s), %d demande(s) de création par jour.', $agency['maxSites'] === null ? 'illimité' : (string) $agency['maxSites'], $agency['dailySiteRequests']));

            return $id;
        });
    }

    #[Route('/admin/plateforme/agences/{id}/role', name: 'admin_plateforme_agences_member', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function member(int $id, Request $request): Response
    {
        return $this->action($request, function () use ($id, $request): int {
            $member = $this->members->set($this->agencies->agency($id), $request->request->get('email'), $request->request->get('role'), $this->getUser()?->getUserIdentifier());
            $this->addFlash('success', $member['changed'] ? sprintf('Compte n° %d : %s.', $member['userId'], $member['role'] ?? 'rôle d\'agence retiré') : 'Aucun changement.');

            return $id;
        });
    }

    /** Une action d'écriture : rôle et jeton CSRF revérifiés ; un refus devient un message ; retour à la page (sur l'agence concernée) */
    private function action(Request $request, callable $do): Response
    {
        $this->denyAccessUnlessGranted(RoleAssignmentPolicy::SUPER_ADMIN);
        if (!$this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Jeton de sécurité invalide : rien n\'a été modifié.');

            return $this->redirect($this->pageUrl([]));
        }
        $id = (int) $request->request->get('agence', 0);
        try {
            $id = (int) ($do() ?? $id);
        } catch (PlatformValidationException $e) {
            foreach ($e->errors as $error) {
                $this->addFlash('danger', htmlspecialchars($error['path'] . ' : ' . $error['message'], ENT_QUOTES));
            }
        } catch (AgencyException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirect($this->pageUrl($id > 0 ? ['agence' => $id] : []));
    }

    /** URL de la page habillée par EasyAdmin (/admin?routeName=…) : une redirection vers la route nue perdrait le menu et le thème */
    private function pageUrl(array $params): string
    {
        try {
            return $this->urls->unsetAll()->setRoute('admin_plateforme_agences', $params)->generateUrl();
        } catch (\Throwable) {
            return $this->generateUrl('admin_plateforme_agences', $params);
        }
    }
}

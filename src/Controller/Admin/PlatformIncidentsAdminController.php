<?php

namespace App\Controller\Admin;

use App\Gestion\Services\GestionConflictException;
use App\Gestion\Services\GestionValidationException;
use App\Incident\Repository\PlatformIncidentRepository;
use App\Incident\Services\PlatformIncidentService;
use App\Incident\UseCase\PlatformIncidentUseCase;
use App\Security\RoleAssignmentPolicy;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Incidents de la plateforme dans EasyAdmin (lot INC-1a, 18/11/2026, `docs/incidents-et-signalements.md`), super administrateur seul :
 * liste groupée et filtrée (état, application, gravité, site), contexte masqué, actions (prendre en charge, corrigé avec le commit,
 * ignorer, lien externe, note interne). Ouverte depuis la page « Plateforme » ; aucun changement du menu (lot G-1 en cours).
 */
class PlatformIncidentsAdminController extends AbstractController
{
    private const CSRF_ID = 'admin_plateforme_incidents';
    private const PAGE_SIZE = 50;

    public function __construct(private readonly PlatformIncidentUseCase $incidents, private readonly AdminUrlGenerator $urls)
    {
    }

    #[Route('/admin/plateforme/incidents', name: 'admin_plateforme_incidents', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyAccessUnlessGranted(RoleAssignmentPolicy::SUPER_ADMIN);
        $filters = [];
        foreach (['status', 'app', 'level', 'tenant'] as $key) { // depuis le menu EasyAdmin, les paramètres arrivent aussi dans les attributs
            $value = trim((string) ($request->query->get($key) ?? $request->attributes->get($key) ?? ''));
            $filters[$key] = $value !== '' ? $value : null;
        }
        $page = max(1, $request->query->getInt('page', 1));
        try {
            $result = $this->incidents->list($filters, $page, self::PAGE_SIZE);
        } catch (GestionValidationException) {
            $this->addFlash('danger', 'Filtre inconnu : la liste complète est affichée.');
            $filters = array_fill_keys(array_keys($filters), null);
            $result = $this->incidents->list($filters, 1, self::PAGE_SIZE);
        }

        return $this->render('admin/plateforme/incidents.html.twig', [
            'result' => $result,
            'filters' => $filters,
            'statuses' => PlatformIncidentRepository::STATUSES,
            'statusLabels' => PlatformIncidentRepository::STATUS_LABELS,
            'statusBadges' => PlatformIncidentRepository::STATUS_BADGES,
            'apps' => [...PlatformIncidentService::APPS, 'backend'],
            'levels' => PlatformIncidentRepository::LEVELS,
            'csrf_id' => self::CSRF_ID,
            'backUrl' => $this->url('admin_plateforme', []),
            'pageUrls' => ['previous' => $this->url('admin_plateforme_incidents', array_filter($filters) + ['page' => $page - 1]),
                'next' => $this->url('admin_plateforme_incidents', array_filter($filters) + ['page' => $page + 1])],
        ]);
    }

    #[Route('/admin/plateforme/incidents/{id}', name: 'admin_plateforme_incident', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function update(int $id, Request $request): Response
    {
        $this->denyAccessUnlessGranted(RoleAssignmentPolicy::SUPER_ADMIN);
        if (!$this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Jeton de sécurité invalide : rien n\'a été modifié.');

            return $this->redirect($this->url('admin_plateforme_incidents', []));
        }
        $body = [];
        foreach (['status', 'note', 'externalUrl', 'fixedIn'] as $key) {
            if ($request->request->has($key)) {
                $body[$key] = trim((string) $request->request->get($key));
            }
        }
        try {
            $this->incidents->update($id, $body, (string) $this->getUser()?->getUserIdentifier());
            $this->addFlash('success', sprintf('Incident n° %d enregistré.', $id));
        } catch (GestionValidationException $e) {
            $this->addFlash('danger', 'Valeur refusée : ' . implode(', ', array_keys($e->fields)) . '.');
        } catch (GestionConflictException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirect($this->url('admin_plateforme_incidents', []));
    }

    /** URL de la page habillée par EasyAdmin (/admin?routeName=…) : une redirection vers la route nue perdrait le menu et le thème */
    private function url(string $route, array $params): string
    {
        try {
            return $this->urls->unsetAll()->setRoute($route, $params)->generateUrl();
        } catch (\Throwable) {
            return $this->generateUrl($route, $params);
        }
    }
}

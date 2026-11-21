<?php

namespace App\Controller\Admin;

use App\Gestion\Services\GestionConflictException;
use App\Gestion\Services\GestionValidationException;
use App\Incident\Controller\PlatformBugReportController;
use App\Incident\Repository\PlatformBugReportRepository;
use App\Incident\UseCase\PlatformBugReportUseCase;
use App\Security\RoleAssignmentPolicy;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Signalements dans EasyAdmin (lot INC-3, 18/11/2026, `docs/incidents-et-signalements.md`), super administrateur seul : liste filtrée,
 * capture (servie ici et par l'API de la plateforme seulement), réponse publique, état, note interne, rattachement à un incident ; durée
 * de conservation des captures (réglage provisoire, fixé par le propriétaire avec le juriste). Ouvert depuis la page « Plateforme » et
 * la page des incidents ; aucun changement du menu.
 */
class PlatformBugReportsAdminController extends AbstractController
{
    private const CSRF_ID = 'admin_plateforme_signalements';

    public function __construct(private readonly PlatformBugReportUseCase $reports, private readonly AdminUrlGenerator $urls)
    {
    }

    #[Route('/admin/plateforme/signalements', name: 'admin_plateforme_bug_reports', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyAccessUnlessGranted(RoleAssignmentPolicy::SUPER_ADMIN);
        $filters = [];
        foreach (['status', 'tenant', 'app'] as $key) {
            $value = trim((string) ($request->query->get($key) ?? $request->attributes->get($key) ?? ''));
            $filters[$key] = $value !== '' ? $value : null;
        }
        try {
            $result = $this->reports->list($filters, max(1, $request->query->getInt('page', 1)), 50);
        } catch (GestionValidationException) {
            $this->addFlash('danger', 'Filtre inconnu : la liste complète est affichée.');
            $filters = array_fill_keys(array_keys($filters), null);
            $result = $this->reports->list($filters, 1, 50);
        }

        return $this->render('admin/plateforme/signalements.html.twig', [
            'result' => $result, 'filters' => $filters, 'statuses' => PlatformBugReportRepository::STATUSES, 'statusLabels' => PlatformBugReportRepository::STATUS_LABELS, 'statusBadges' => PlatformBugReportRepository::STATUS_BADGES, 'csrf_id' => self::CSRF_ID,
            'screenshotDays' => $this->reports->screenshotDays(), 'backUrl' => $this->url('admin_plateforme_incidents'),
        ]);
    }

    #[Route('/admin/plateforme/signalements/{id}', name: 'admin_plateforme_bug_report', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function update(int $id, Request $request): Response
    {
        $this->denyAccessUnlessGranted(RoleAssignmentPolicy::SUPER_ADMIN);
        if ($this->csrf($request)) {
            $body = [];
            foreach (['status', 'answer', 'note', 'incidentId'] as $key) {
                if ($request->request->has($key)) {
                    $body[$key] = trim((string) $request->request->get($key));
                }
            }
            try {
                $out = $this->reports->update($id, $body, (string) $this->getUser()?->getUserIdentifier());
                $this->addFlash('success', sprintf('Signalement n° %d enregistré%s.', $id, $out['mailed'] ? ', le signaleur est prévenu' : ''));
            } catch (GestionValidationException $e) {
                $this->addFlash('danger', 'Valeur refusée : ' . implode(', ', array_keys($e->fields)) . '.');
            } catch (GestionConflictException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        }

        return $this->redirect($this->url('admin_plateforme_bug_reports'));
    }

    #[Route('/admin/plateforme/signalements/{id}/capture', name: 'admin_plateforme_bug_report_capture', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function capture(int $id): Response
    {
        $this->denyAccessUnlessGranted(RoleAssignmentPolicy::SUPER_ADMIN);
        try {
            return PlatformBugReportController::image($this->reports->screenshotFile($id));
        } catch (GestionConflictException $e) {
            throw $this->createNotFoundException($e->getMessage());
        }
    }

    #[Route('/admin/plateforme/signalements/conservation', name: 'admin_plateforme_bug_reports_retention', methods: ['POST'])]
    public function retention(Request $request): Response
    {
        $this->denyAccessUnlessGranted(RoleAssignmentPolicy::SUPER_ADMIN);
        if ($this->csrf($request)) {
            try {
                $this->reports->setScreenshotDays(trim((string) $request->request->get('screenshotDays')), $this->getUser()?->getUserIdentifier());
                $this->addFlash('success', 'Durée de conservation des captures enregistrée.');
            } catch (GestionValidationException) {
                $this->addFlash('danger', 'Durée refusée : de 1 à 3 650 jours.');
            }
        }

        return $this->redirect($this->url('admin_plateforme_bug_reports'));
    }

    private function csrf(Request $request): bool
    {
        if ($this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_token'))) {
            return true;
        }
        $this->addFlash('danger', 'Jeton de sécurité invalide : rien n\'a été modifié.');

        return false;
    }

    /** URL de la page habillée par EasyAdmin (/admin?routeName=…) : une redirection vers la route nue perdrait le menu et le thème */
    private function url(string $route): string
    {
        try {
            return $this->urls->unsetAll()->setRoute($route, [])->generateUrl();
        } catch (\Throwable) {
            return $this->generateUrl($route);
        }
    }
}

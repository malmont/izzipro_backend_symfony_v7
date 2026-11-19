<?php

namespace App\Controller\Admin;

use App\Security\RoleAssignmentPolicy;
use App\UseCase\TechDashboardUseCase\GetTechDashboardUseCase;
use App\UseCase\TechDashboardUseCase\UpdateTechThresholdsUseCase;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Écran « Tableau de bord technique » (T2, 03/11/2026) : quel site consomme le plus (classement par ressource sur 24 h, 7 j, 30 j),
 * détail d'un site avec ses courbes, mesures de la plateforme (PHP-FPM, disque, sauvegarde, timers) avec l'indicateur bon / moyen /
 * mauvais, routes les plus lourdes, seuils modifiables. Réservé au propriétaire de la plateforme (ROLE_SUPER_ADMIN) : les mesures
 * couvrent tous les sites. Aucune valeur de configuration ni de secret n'est affichée. Aucune route d'API : EasyAdmin seulement.
 */
class TechDashboardAdminController extends AbstractController
{
    private const CSRF_ID = 'admin_technique';

    public function __construct(
        private readonly GetTechDashboardUseCase $getDashboard,
        private readonly UpdateTechThresholdsUseCase $updateThresholds,
        private readonly ?AdminUrlGenerator $urls = null,
    ) {
    }

    #[Route('/admin/technique', name: 'admin_technique', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyAccessUnlessGranted(RoleAssignmentPolicy::SUPER_ADMIN);
        $model = $this->getDashboard->execute($request->query->get('fenetre'), $request->query->get('site'), $request->query->get('tri'));

        return $this->render('admin/technique/index.html.twig', $model + ['csrf_id' => self::CSRF_ID]);
    }

    #[Route('/admin/technique/seuils', name: 'admin_technique_seuils', methods: ['POST'])]
    public function thresholds(Request $request): Response
    {
        $this->denyAccessUnlessGranted(RoleAssignmentPolicy::SUPER_ADMIN);
        if (!$this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Jeton de sécurité invalide : seuils non modifiés.');
        } else {
            $errors = $this->updateThresholds->execute((array) $request->request->all('seuils'));
            if ($errors === []) {
                $this->addFlash('success', 'Seuils enregistrés.');
            }
            foreach ($errors as $error) {
                $this->addFlash('danger', $error);
            }
        }

        // retour à la page HABILLÉE par EasyAdmin : une redirection vers la route nue perdrait le menu et le thème (page blanche)
        $params = array_filter(['fenetre' => $request->request->get('fenetre'), 'site' => $request->request->get('site')]);
        try {
            if ($this->urls !== null) {
                return $this->redirect($this->urls->unsetAll()->setRoute('admin_technique', $params)->generateUrl() . '#seuils');
            }
        } catch (\Throwable) {
            // pas de contexte d'EasyAdmin : la route nue
        }

        return $this->redirectToRoute('admin_technique', $params + ['_fragment' => 'seuils']);
    }
}

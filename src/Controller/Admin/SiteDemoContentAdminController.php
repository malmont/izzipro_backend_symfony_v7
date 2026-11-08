<?php

namespace App\Controller\Admin;

use App\Services\SiteContentService\SiteContentException;
use App\Services\SiteContentService\SiteContentSpec;
use App\UseCase\SiteContentUseCase\ManageSiteContentUseCase;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

/** Contenu d'exemple (lot 3, 08/11/2026) : bouton « Effacer le contenu d'exemple de cet écran » du panneau de droite des écrans de composants. */
#[IsGranted('ROLE_ADMIN')]
class SiteDemoContentAdminController extends AbstractController
{
    public const CSRF = 'demo_content';

    public function __construct(private readonly ManageSiteContentUseCase $siteContent, private readonly AdminUrlGenerator $urls)
    {
    }

    #[Route('/admin/contenu-exemple/effacer/{resource}', name: 'admin_demo_content_delete', methods: ['POST'], requirements: ['resource' => '[a-z-]+'])]
    public function delete(string $resource, Request $request): Response
    {
        $back = $request->headers->get('referer') ?: $this->urls->unsetAll()->generateUrl();
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Formulaire expiré : réessayez.');

            return $this->redirect($back);
        }
        if (!isset(SiteContentSpec::RESOURCES[$resource])) {
            $this->addFlash('danger', 'Ressource inconnue.');

            return $this->redirect($back);
        }
        try {
            $result = $this->siteContent->delete('fr', [$resource]);
        } catch (SiteContentException $e) {
            $this->addFlash('danger', $e->getMessage());

            return $this->redirect($back);
        }
        $deleted = array_sum((array) $result['deleted']);
        $kept = count($result['kept']);
        $this->addFlash('success', sprintf('%d contenu(s) d\'exemple effacé(s)%s.', $deleted, $kept > 0 ? sprintf(', %d gardé(s) parce qu\'une fiche du site s\'en sert', $kept) : ''));

        return $this->redirect($back);
    }
}

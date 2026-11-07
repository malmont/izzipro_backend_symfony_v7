<?php

namespace App\Controller\Admin;

use App\Services\Platform\AdminStepUrlResolver;
use App\Services\Platform\SiteControlsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Page « Contrôles » (`/admin/controles`, 07/11/2026) : ce qui cloche dans les données du site, avec l'écran où corriger. Administrateurs du site. */
#[IsGranted('ROLE_ADMIN')]
class ControlsConsoleAdminController extends AbstractController
{
    public function __construct(private readonly SiteControlsService $controls, private readonly AdminStepUrlResolver $screens)
    {
    }

    #[Route('/admin/controles', name: 'admin_controles', methods: ['GET'])]
    public function index(): Response
    {
        $overview = $this->controls->overview();
        foreach ($overview['controls'] as $i => $control) {
            $overview['controls'][$i]['url'] = $this->screens->screenUrl($control['screen']);
        }

        return $this->render('admin/controles/index.html.twig', ['overview' => $overview]);
    }
}

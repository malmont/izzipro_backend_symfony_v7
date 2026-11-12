<?php

namespace App\Controller\Admin;

use App\Gestion\Services\GestionSetupService;
use App\Services\Platform\AdminStepUrlResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Page « Application de gestion : réglages et cheminement » (`/admin/gestion`, lot CHE, 12/11/2026) : les étapes pour préparer l'application
 * de gestion, dans l'ordre, chacune liée à son écran ; sans module accordé, l'essai ou la demande. Administrateurs du site ; habillage EasyAdmin.
 */
#[IsGranted('ROLE_ADMIN')]
class GestionConsoleAdminController extends AbstractController
{
    public function __construct(private readonly GestionSetupService $setup, private readonly AdminStepUrlResolver $screens)
    {
    }

    #[Route('/admin/gestion', name: 'admin_gestion', methods: ['GET'])]
    public function index(): Response
    {
        $overview = $this->setup->overview();
        foreach ($overview['steps'] as $i => $step) {
            $overview['steps'][$i]['url'] = $this->screens->stepUrl($step);
        }
        $overview['obtainUrl'] = $overview['obtain'] !== null ? $this->screens->stepUrl($overview['obtain']) : null;

        return $this->render('admin/gestion/index.html.twig', ['overview' => $overview, 'monSiteUrl' => $this->screens->routeUrl('admin_mon_site')]);
    }
}

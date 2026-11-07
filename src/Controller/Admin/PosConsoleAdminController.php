<?php

namespace App\Controller\Admin;

use App\Services\CaisseService\PosSetupService;
use App\Services\Platform\AdminStepUrlResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Page « Caisse : réglages et cheminement » (`/admin/caisse`, 07/11/2026) : la liste de contrôle d'ouverture du magasin, étape par étape,
 * avec l'écran exact de chacune et un résumé des réglages. Administrateurs du site ; habillage EasyAdmin.
 */
#[IsGranted('ROLE_ADMIN')]
class PosConsoleAdminController extends AbstractController
{
    public function __construct(private readonly PosSetupService $setup, private readonly AdminStepUrlResolver $screens)
    {
    }

    #[Route('/admin/caisse', name: 'admin_caisse', methods: ['GET'])]
    public function index(): Response
    {
        $overview = $this->setup->overview();
        foreach ($overview['steps'] as $i => $step) {
            $overview['steps'][$i]['url'] = $this->screens->screenUrl($step['screen']);
        }

        $overview['obtainUrl'] = $overview['obtain'] !== null ? $this->screens->stepUrl($overview['obtain']) : null;

        return $this->render('admin/caisse/index.html.twig', ['overview' => $overview, 'monSiteUrl' => $this->screens->routeUrl('admin_mon_site')]);
    }
}

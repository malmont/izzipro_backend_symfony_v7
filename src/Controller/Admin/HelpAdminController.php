<?php

namespace App\Controller\Admin;

use App\Services\Help\HelpCatalogService;
use App\Services\Platform\AdminStepUrlResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Pages d'aide dans le back-office (`/admin/aide`, `/admin/aide/{clé}`, 07/11/2026), avec recherche et lien vers l'écran concerné. */
#[IsGranted('ROLE_ADMIN')]
class HelpAdminController extends AbstractController
{
    public function __construct(private readonly HelpCatalogService $help, private readonly AdminStepUrlResolver $screens)
    {
    }

    #[Route('/admin/aide', name: 'admin_aide', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $q = trim((string) ($request->query->get('q') ?? $request->attributes->get('q') ?? ''));
        $pages = $q === '' ? $this->help->all() : $this->help->search($q);
        foreach ($pages as $i => $p) {
            $pages[$i]['url'] = $this->screens->routeUrl('admin_aide_page', ['key' => $p['key']]);
        }

        return $this->render('admin/aide/index.html.twig', ['pages' => $pages, 'q' => $q, 'page' => null, 'screenUrl' => null]);
    }

    #[Route('/admin/aide/{key}', name: 'admin_aide_page', requirements: ['key' => '[a-z0-9_-]+'], methods: ['GET'])]
    public function page(string $key): Response
    {
        $page = $this->help->get($key);
        if ($page === null) {
            throw $this->createNotFoundException('Page d\'aide inconnue.');
        }
        $pages = $this->help->all();
        foreach ($pages as $i => $p) {
            $pages[$i]['url'] = $this->screens->routeUrl('admin_aide_page', ['key' => $p['key']]);
        }

        return $this->render('admin/aide/index.html.twig', ['pages' => $pages, 'q' => '', 'page' => $page, 'screenUrl' => $this->screens->screenUrl($page['screen'])]);
    }
}

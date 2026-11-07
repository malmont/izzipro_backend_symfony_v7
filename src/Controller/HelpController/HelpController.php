<?php

namespace App\Controller\HelpController;

use App\Services\Help\HelpCatalogService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Aide intégrée pour les administrateurs (07/11/2026) : GET /api/site/help?q= → liste {key, title, summary, screen, steps, settings} ;
 * GET /api/site/help/{key} → la page avec `html` ; 404 HELP_NOT_FOUND. Lecture locale de docs/aide, aucune donnée du site.
 */
#[Route('/api/site/help')]
#[IsGranted('ROLE_ADMIN')]
class HelpController extends AbstractController
{
    public function __construct(private readonly HelpCatalogService $help)
    {
    }

    #[Route('', name: 'api_site_help_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $q = trim((string) $request->query->get('q', ''));

        return $this->json(['pages' => $q === '' ? $this->help->all() : $this->help->search($q)]);
    }

    #[Route('/{key}', name: 'api_site_help_page', methods: ['GET'], requirements: ['key' => '[a-z0-9_-]+'])]
    public function page(string $key): JsonResponse
    {
        $page = $this->help->get($key);

        return $page === null ? $this->json(['error' => 'HELP_NOT_FOUND', 'code' => 'HELP_NOT_FOUND', 'message' => 'Page d\'aide inconnue.'], 404) : $this->json($page);
    }
}

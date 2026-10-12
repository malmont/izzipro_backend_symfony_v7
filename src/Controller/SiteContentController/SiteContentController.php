<?php

namespace App\Controller\SiteContentController;

use App\Controller\LandingContentController\LandingContentController;
use App\Services\SiteContentService\SiteContentException;
use App\UseCase\SiteContentUseCase\ManageSiteContentUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Contenu d'exemple du site (12/10/2026, ROLE_ADMIN du site) : copie depuis le site de référence (gmasuite), fiches
 * encore d'exemple (isDemo) et leur suppression. Un nouveau site a déjà ce contenu : il est copié de gmasuite à sa
 * création. Les fiches se lisent et se modifient ensuite par leurs routes habituelles.
 */
#[IsGranted('ROLE_ADMIN')]
class SiteContentController extends AbstractController
{
    public function __construct(private readonly ManageSiteContentUseCase $siteContent)
    {
    }

    #[Route('/api/site-content/seed', name: 'api_site_content_seed', methods: ['POST'])]
    public function seed(Request $request): JsonResponse
    {
        return $this->handle(function () use ($request) {
            $result = $this->siteContent->seed($this->body($request));

            return $this->json($result, (array) $result['created'] === [] ? 200 : 201);
        });
    }

    #[Route('/api/site-content/demo', name: 'api_site_content_demo', methods: ['GET'])]
    public function demo(Request $request): JsonResponse
    {
        return $this->handle(fn () => $this->json($this->siteContent->list((string) $request->query->get('locale', 'fr'))));
    }

    #[Route('/api/site-content/demo', name: 'api_site_content_demo_delete', methods: ['DELETE'])]
    public function deleteDemo(Request $request): JsonResponse
    {
        return $this->handle(fn () => $this->json($this->siteContent->delete((string) $request->query->get('locale', 'fr'))));
    }

    private function body(Request $request): mixed
    {
        if (strlen($request->getContent()) > LandingContentController::MAX_BODY_BYTES) {
            throw new SiteContentException(413, 'Corps de la requête trop volumineux.');
        }
        try {
            return json_decode($request->getContent() ?: '{}', false, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new SiteContentException(400, 'Corps JSON invalide.');
        }
    }

    private function handle(callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (SiteContentException $e) {
            return $this->json(array_filter(['error' => $e->getMessage(), 'errors' => $e->details]), $e->getStatusCode());
        }
    }
}

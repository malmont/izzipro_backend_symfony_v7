<?php

namespace App\Controller\SiteFeaturesController;

use App\UseCase\SiteCharterUseCase\SiteCharterUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Charte et brief du site (08/11/2026) : la source unique que l'assistant du backend et celui de l'éditeur Next relisent.
 * GET /api/site/charter → { brief, charter, style, designMd, document { name } | null, pagesSavedAt, composedAt, options }
 * PUT /api/site/charter { brief?, charter?, style?, designMd? } → 200 la charte, ou 422 { errors [{ path, message }] }
 * POST /api/site/charter/document (multipart, champ « file », PDF de 7,5 Mo au plus) → 200 ; DELETE → 204
 */
#[Route('/api/site/charter')]
#[IsGranted('ROLE_ADMIN')]
class SiteCharterController extends AbstractController
{
    public function __construct(private readonly SiteCharterUseCase $useCase)
    {
    }

    #[Route('', name: 'api_site_charter_get', methods: ['GET'])]
    public function show(): JsonResponse
    {
        return $this->json($this->useCase->get());
    }

    #[Route('', name: 'api_site_charter_update', methods: ['PUT'])]
    public function update(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true);
        if (!is_array($body)) {
            return $this->json(['error' => 'INVALID_JSON', 'message' => 'Corps JSON attendu'], 400);
        }
        $result = $this->useCase->update($body, $this->getUser()?->getUserIdentifier());
        if ($result['errors'] !== []) {
            return $this->json(['error' => 'VALIDATION', 'errors' => $result['errors']], 422);
        }

        return $this->json($result['charter']);
    }

    #[Route('/document', name: 'api_site_charter_document', methods: ['POST'])]
    public function document(Request $request): JsonResponse
    {
        $file = $request->files->get('file');
        if ($file === null || !$file->isValid()) {
            return $this->json(['error' => 'FILE_REQUIRED', 'message' => 'Champ « file » attendu (PDF)'], 400);
        }
        $message = $this->useCase->storeDocument((string) file_get_contents($file->getPathname()), (string) $file->getClientOriginalName(), $this->getUser()?->getUserIdentifier());
        if ($message !== null) {
            return $this->json(['error' => 'DOCUMENT_INVALID', 'message' => $message], 422);
        }

        return $this->json($this->useCase->get());
    }

    #[Route('/document', name: 'api_site_charter_document_delete', methods: ['DELETE'])]
    public function removeDocument(): JsonResponse
    {
        $this->useCase->removeDocument($this->getUser()?->getUserIdentifier());

        return $this->json(null, 204);
    }
}

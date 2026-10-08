<?php

namespace App\Controller\ContentAuditController;

use App\Services\ContentAuditService\ContentAuditException;
use App\UseCase\ContentAuditUseCase\ContentAuditUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Journal des écritures des éditeurs (landing pages et boutique ; ROLE_ADMIN, propre au site) */
#[Route('/api/landingpage-audit')]
#[IsGranted('ROLE_ADMIN')]
class ContentAuditController extends AbstractController
{
    public function __construct(private readonly ContentAuditUseCase $useCase)
    {
    }

    /** ?resource=presentations|…|entreprise|landingpage-settings|boutique-settings|landingpage-site-models|boutique-site-models|media, resourceId, page, limit */
    #[Route('', name: 'api_content_audit_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $q = $request->query;

        return $this->handle(fn () => $this->json($this->useCase->list(
            $q->has('resource') ? (string) $q->get('resource') : null,
            $q->has('resourceId') ? (string) $q->get('resourceId') : null,
            $q->has('page') ? (string) $q->get('page') : null,
            $q->has('limit') ? (string) $q->get('limit') : null
        )));
    }

    #[Route('/{id}', name: 'api_content_audit_get', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function getOne(int $id): JsonResponse
    {
        return $this->handle(fn () => $this->json($this->useCase->get($id)));
    }

    /** Retour à l'état d'avant cette écriture ; ?force=1 si la ressource a été modifiée depuis */
    #[Route('/{id}/restore', name: 'api_content_audit_restore', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function restore(int $id, Request $request): JsonResponse
    {
        return $this->handle(fn () => $this->json($this->useCase->restore($id, $request->query->getBoolean('force'), $request->getSchemeAndHttpHost())));
    }

    private function handle(callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (ContentAuditException $e) {
            return $this->json(array_filter(['error' => $e->getMessage(), 'errors' => $e->errors]), $e->getStatusCode());
        }
    }
}

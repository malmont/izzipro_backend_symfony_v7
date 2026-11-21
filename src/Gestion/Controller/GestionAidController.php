<?php

namespace App\Gestion\Controller;

use App\Gestion\Services\GestionValidationException;
use App\Gestion\UseCase\GestionAidUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Aides de la gestion (lot FIS-2, 21/11/2026, `docs/aides-gestion.md`), `ROLE_ADMIN` (`access_control` `/api/gestion`), module de gestion :
 * gestion web et application de gestion mobile. Le produit et les flux sont calculés ; une avance remboursable crée son prêt à 0 %.
 */
#[Route('/api/gestion/aides')]
class GestionAidController extends AbstractController
{
    public function __construct(private readonly GestionAidUseCase $useCase)
    {
    }

    #[Route('', name: 'gestion_aides_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return $this->json(['aids' => $this->useCase->list()]);
    }

    #[Route('', name: 'gestion_aides_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        return $this->json($this->useCase->create($this->body($request), $this->getUser()?->getUserIdentifier()), Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'gestion_aides_get', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function get(int $id): JsonResponse
    {
        return $this->json($this->useCase->get($id));
    }

    #[Route('/{id}', name: 'gestion_aides_update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function update(int $id, Request $request): JsonResponse
    {
        return $this->json($this->useCase->update($id, $this->body($request), $this->getUser()?->getUserIdentifier()));
    }

    #[Route('/{id}', name: 'gestion_aides_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id): Response
    {
        $this->useCase->delete($id);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /** `{ state: todo | kept | broken }` : l'état d'une condition, par son rang (ordre des échéances) */
    #[Route('/{id}/conditions/{index}', name: 'gestion_aides_condition', methods: ['PATCH'], requirements: ['id' => '\d+', 'index' => '\d+'])]
    public function condition(int $id, int $index, Request $request): JsonResponse
    {
        return $this->json($this->useCase->setConditionState($id, $index, $this->body($request)));
    }

    /** `{ documentId }` : rattache un document de la gestion déjà déposé, comme justificatif */
    #[Route('/{id}/justificatifs', name: 'gestion_aides_document_add', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function attachDocument(int $id, Request $request): JsonResponse
    {
        return $this->json($this->useCase->attachDocument($id, $this->body($request)), Response::HTTP_CREATED);
    }

    #[Route('/{id}/justificatifs/{documentId}', name: 'gestion_aides_document_remove', methods: ['DELETE'], requirements: ['id' => '\d+', 'documentId' => '\d+'])]
    public function detachDocument(int $id, int $documentId): JsonResponse
    {
        return $this->json($this->useCase->detachDocument($id, $documentId));
    }

    private function body(Request $request): array
    {
        $body = json_decode((string) $request->getContent(), true);
        if (!is_array($body)) {
            throw new GestionValidationException(['body' => 'INVALID_JSON']);
        }

        return $body;
    }
}

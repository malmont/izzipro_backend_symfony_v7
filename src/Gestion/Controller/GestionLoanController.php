<?php

namespace App\Gestion\Controller;

use App\Gestion\Services\GestionValidationException;
use App\Gestion\UseCase\GestionLoanUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Prêts de la gestion (lot FIS-1, 19/11/2026, `docs/prets-gestion.md`), `ROLE_ADMIN` (`access_control` `/api/gestion`), module de gestion :
 * gestion web et application de gestion mobile. L'échéancier est calculé ; `POST /apercu` le rend sans rien écrire.
 */
#[Route('/api/gestion/prets')]
class GestionLoanController extends AbstractController
{
    public function __construct(private readonly GestionLoanUseCase $useCase)
    {
    }

    #[Route('', name: 'gestion_prets_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return $this->json(['loans' => $this->useCase->list()]);
    }

    #[Route('/apercu', name: 'gestion_prets_apercu', methods: ['POST'])]
    public function preview(Request $request): JsonResponse
    {
        return $this->json($this->useCase->preview($this->body($request)));
    }

    /** Pour un exercice : `?from=AAAA-MM-JJ&to=AAAA-MM-JJ` → par prêt, capital au début et à la fin, capital remboursé, intérêts, assurance, frais */
    #[Route('/exercice', name: 'gestion_prets_exercice', methods: ['GET'])]
    public function financialYear(Request $request): JsonResponse
    {
        return $this->json($this->useCase->financialYear($request->query->get('from'), $request->query->get('to')));
    }

    #[Route('', name: 'gestion_prets_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        return $this->json($this->useCase->create($this->body($request), $this->getUser()?->getUserIdentifier()), Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'gestion_prets_get', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function get(int $id): JsonResponse
    {
        return $this->json($this->useCase->get($id));
    }

    #[Route('/{id}', name: 'gestion_prets_update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function update(int $id, Request $request): JsonResponse
    {
        return $this->json($this->useCase->update($id, $this->body($request)));
    }

    #[Route('/{id}', name: 'gestion_prets_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id): Response
    {
        $this->useCase->delete($id);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id}/remboursements', name: 'gestion_prets_prepayment_add', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function addPrepayment(int $id, Request $request): JsonResponse
    {
        return $this->json($this->useCase->addPrepayment($id, $this->body($request)), Response::HTTP_CREATED);
    }

    #[Route('/{id}/remboursements/{index}', name: 'gestion_prets_prepayment_remove', methods: ['DELETE'], requirements: ['id' => '\d+', 'index' => '\d+'])]
    public function removePrepayment(int $id, int $index): JsonResponse
    {
        return $this->json($this->useCase->removePrepayment($id, $index));
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

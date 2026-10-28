<?php

namespace App\Controller\ServiceFamilyController;

use App\UseCase\FormSubmissionUseCase\FormSubmissionException;
use App\UseCase\ServiceFamilyUseCase\ManageServiceFamiliesUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Familles de prestations (28/10/2026) : lecture publique, écritures réservées à l'administrateur */
#[Route('/api/service-families')]
class ServiceFamilyController extends AbstractController
{
    public function __construct(private readonly ManageServiceFamiliesUseCase $families)
    {
    }

    #[Route('', name: 'api_service_families_list', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return $this->json($this->families->list());
    }

    #[Route('', name: 'api_service_families_create', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function create(Request $request): JsonResponse
    {
        return $this->guard(fn () => $this->json($this->families->create(json_decode($request->getContent(), false)), Response::HTTP_CREATED));
    }

    #[Route('/{id}', name: 'api_service_families_update', methods: ['PUT'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function update(int $id, Request $request): JsonResponse
    {
        return $this->guard(fn () => $this->json($this->families->update($id, json_decode($request->getContent(), false))));
    }

    #[Route('/{id}', name: 'api_service_families_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(int $id): Response
    {
        return $this->guard(function () use ($id) {
            $this->families->delete($id);

            return new Response(null, Response::HTTP_NO_CONTENT);
        });
    }

    private function guard(callable $action): Response
    {
        try {
            return $action();
        } catch (FormSubmissionException $e) {
            return $this->json(['error' => $e->getMessage(), 'message' => $e->getMessage()] + ($e->errors ? ['errors' => $e->errors] : []), $e->getStatusCode());
        }
    }
}

<?php

namespace App\Controller\FormSubmissionController;

use App\UseCase\FormSubmissionUseCase\ExportFormSubmissionsUseCase;
use App\UseCase\FormSubmissionUseCase\FormSubmissionException;
use App\UseCase\FormSubmissionUseCase\ListFormSubmissionsUseCase;
use App\UseCase\FormSubmissionUseCase\ManageFormSubmissionUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Demandes reçues par les formulaires à étapes, pour l'administrateur du site (page « Demandes reçues » de l'éditeur,
 * 28/10/2026). Règle access_control en premier rempart (security.yaml). L'envoi public (POST) est à part (lot 3).
 */
#[Route('/api/form-submissions')]
#[IsGranted('ROLE_ADMIN')]
class FormSubmissionAdminController extends AbstractController
{
    public function __construct(
        private readonly ListFormSubmissionsUseCase $list,
        private readonly ManageFormSubmissionUseCase $manage,
        private readonly ExportFormSubmissionsUseCase $export
    ) {
    }

    #[Route('', name: 'api_form_submissions_list', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        return $this->guard(fn () => $this->json($this->list->execute($request->query->all())));
    }

    #[Route('/forms', name: 'api_form_submissions_forms', methods: ['GET'])]
    public function forms(): JsonResponse
    {
        return $this->json($this->list->forms());
    }

    #[Route('/export', name: 'api_form_submissions_export', methods: ['GET'])]
    public function export(Request $request): Response
    {
        return $this->guard(function () use ($request) {
            $export = $this->export->execute($request->query->all());

            return new Response($export['csv'], 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => sprintf('attachment; filename="%s"', $export['filename']),
                'X-Export-Count' => (string) $export['count'],
                'X-Export-Truncated' => $export['truncated'] ? '1' : '0',
                'Cache-Control' => 'no-store',
            ]);
        });
    }

    #[Route('/{id}', name: 'api_form_submissions_get', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): JsonResponse
    {
        return $this->guard(fn () => $this->json($this->manage->get($id)));
    }

    #[Route('/{id}', name: 'api_form_submissions_status', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function status(int $id, Request $request): JsonResponse
    {
        return $this->guard(fn () => $this->json($this->manage->updateStatus($id, json_decode($request->getContent(), false))));
    }

    #[Route('/{id}', name: 'api_form_submissions_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id): Response
    {
        return $this->guard(function () use ($id) {
            $this->manage->delete($id);

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

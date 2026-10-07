<?php

namespace App\Controller\LandingSiteModelController;

use App\Services\LandingSiteModelService\LandingSiteModelException;
use App\Services\LandingSiteModelService\LandingSiteModelService;
use App\UseCase\LandingSiteModelUseCase\GetLandingSiteModelUseCase;
use App\UseCase\LandingSiteModelUseCase\ListLandingSiteModelsUseCase;
use App\UseCase\LandingSiteModelUseCase\SaveLandingSiteModelUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Bibliothèque de modèles de site de l'éditeur des landing pages (ROLE_ADMIN, propre au site). Un modèle d'un autre
 * site est dans une autre base : 404.
 */
#[Route('/api/landingpage-site-models')]
#[IsGranted('ROLE_ADMIN')]
class LandingSiteModelController extends AbstractController
{
    /** Corps : configuration de 2 Mo au plus, plus les autres champs */
    private const MAX_BODY_BYTES = LandingSiteModelService::MAX_CONFIGURATION_BYTES + 16384;

    public function __construct(
        private readonly ListLandingSiteModelsUseCase $listUseCase,
        private readonly GetLandingSiteModelUseCase $getUseCase,
        private readonly SaveLandingSiteModelUseCase $saveUseCase
    ) {
    }

    #[Route('', name: 'api_landing_site_models_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return $this->json($this->listUseCase->execute());
    }

    #[Route('/{id}', name: 'api_landing_site_models_get', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function getOne(int $id): JsonResponse
    {
        return $this->handle(fn () => new JsonResponse($this->getUseCase->execute($id), 200, [], true));
    }

    #[Route('', name: 'api_landing_site_models_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        return $this->handle(fn () => $this->json($this->saveUseCase->create($this->body($request), $this->getUser()?->getUserIdentifier()), 201));
    }

    #[Route('/{id}', name: 'api_landing_site_models_update', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function update(int $id, Request $request): JsonResponse
    {
        return $this->handle(fn () => $this->json($this->saveUseCase->update($id, $this->body($request))));
    }

    #[Route('/{id}', name: 'api_landing_site_models_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id): JsonResponse
    {
        return $this->handle(function () use ($id) {
            $this->saveUseCase->delete($id);

            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        });
    }

    /** Corps relu en objets : un {} décodé en tableau PHP deviendrait [] (contenu modifié) */
    private function body(Request $request): mixed
    {
        if (strlen($request->getContent()) > self::MAX_BODY_BYTES) {
            throw new LandingSiteModelException(413, sprintf('Corps trop volumineux : configuration de %d Mo au plus.', LandingSiteModelService::MAX_CONFIGURATION_BYTES / 1048576));
        }
        try {
            return json_decode($request->getContent(), false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            throw new LandingSiteModelException(400, 'Corps JSON invalide.');
        }
    }

    private function handle(callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (LandingSiteModelException $e) {
            return $this->json(array_filter(['error' => $e->getMessage(), 'errors' => $e->errors]), $e->getStatusCode());
        }
    }
}

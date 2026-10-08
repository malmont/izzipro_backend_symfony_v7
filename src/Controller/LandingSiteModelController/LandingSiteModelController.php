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
 * Bibliothèque de modèles de site (ROLE_ADMIN, propre au site), une par application :
 * /api/landingpage-site-models (éditeur des landing pages) et /api/boutique-site-models (éditeur de la boutique).
 * Un modèle d'un autre site est dans une autre base, un modèle de l'autre application est hors de la liste : 404.
 */
#[Route('/api/{app}-site-models', requirements: ['app' => 'landingpage|boutique'])]
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
    public function list(string $app): JsonResponse
    {
        return $this->json($this->listUseCase->execute($app));
    }

    #[Route('/{id}', name: 'api_landing_site_models_get', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function getOne(string $app, int $id): JsonResponse
    {
        return $this->handle(fn () => new JsonResponse($this->getUseCase->execute($id, $app), 200, [], true));
    }

    #[Route('', name: 'api_landing_site_models_create', methods: ['POST'])]
    public function create(string $app, Request $request): JsonResponse
    {
        return $this->handle(fn () => $this->json($this->saveUseCase->create($app, $this->body($request), $this->getUser()?->getUserIdentifier()), 201));
    }

    #[Route('/{id}', name: 'api_landing_site_models_update', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function update(string $app, int $id, Request $request): JsonResponse
    {
        return $this->handle(fn () => $this->json($this->saveUseCase->update($id, $app, $this->body($request))));
    }

    #[Route('/{id}', name: 'api_landing_site_models_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(string $app, int $id): JsonResponse
    {
        return $this->handle(function () use ($app, $id) {
            $this->saveUseCase->delete($id, $app);

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

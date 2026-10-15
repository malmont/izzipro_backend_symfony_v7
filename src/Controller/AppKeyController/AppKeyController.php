<?php

namespace App\Controller\AppKeyController;

use App\Dto\AppKeyInputDto;
use App\Services\AppKeyService\AppKeyException;
use App\UseCase\AppKeyUseCase\GenerateAppKeyUseCase;
use App\UseCase\AppKeyUseCase\ListAppKeysUseCase;
use App\UseCase\AppKeyUseCase\RevokeAppKeyUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Clés d'activation du site (ROLE_ADMIN, access_control) : liste, génération (clé rendue une fois), révocation */
class AppKeyController extends AbstractController
{
    public function __construct(
        private readonly ListAppKeysUseCase $list,
        private readonly GenerateAppKeyUseCase $generate,
        private readonly RevokeAppKeyUseCase $revoke
    ) {
    }

    #[Route('/api/app-keys', name: 'app_keys_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return $this->json($this->list->execute());
    }

    #[Route('/api/app-keys', name: 'app_keys_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        try {
            $input = AppKeyInputDto::fromRequestBody($request->getContent());

            return $this->json($this->generate->execute($input, $this->getUser()?->getUserIdentifier() ?? 'admin', $request->getSchemeAndHttpHost()), JsonResponse::HTTP_CREATED);
        } catch (AppKeyException $e) {
            return $this->json($e->toBody(), $e->status);
        }
    }

    #[Route('/api/app-keys/{id}', name: 'app_keys_revoke', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function revoke(int $id): JsonResponse
    {
        try {
            return $this->json($this->revoke->execute($id));
        } catch (AppKeyException $e) {
            return $this->json($e->toBody(), $e->status);
        }
    }
}

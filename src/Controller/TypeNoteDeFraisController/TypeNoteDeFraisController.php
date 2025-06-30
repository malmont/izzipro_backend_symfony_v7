<?php

namespace App\Controller\TypeNoteDeFraisController;

use App\UseCase\TypeNoteDeFraisUseCase\GetListTypeNoteDeFraisUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use App\Services\TenantCacheService;
use Symfony\Contracts\Cache\ItemInterface;

class TypeNoteDeFraisController extends AbstractController
{
    private GetListTypeNoteDeFraisUseCase $useCase;
    private TenantCacheService $cache;

    public function __construct(GetListTypeNoteDeFraisUseCase $useCase, TenantCacheService $cache)
    {
        $this->useCase = $useCase;
        $this->cache = $cache;
    }

    #[Route('/api/type-note-de-frais', name: 'api_type_note_de_frais', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $cacheKey = 'api_type_note_de_frais_all';
        $data = $this->cache->get(
            $cacheKey,
            function (ItemInterface $item) {
                $item->expiresAfter(3600);
                $dtoList = $this->useCase->execute();
                return array_map(function ($dto) {
                    return [
                        'id'    => $dto->id,
                        'name'  => $dto->name,
                        'image' => $dto->image,
                    ];
                }, $dtoList);
            },
        );
        return new JsonResponse($data, JsonResponse::HTTP_OK);
    }
}

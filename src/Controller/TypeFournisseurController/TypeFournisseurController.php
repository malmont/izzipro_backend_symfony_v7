<?php

namespace App\Controller\TypeFournisseurController;

use App\UseCase\TypeFournisseurUseCase\GetListTypeFournisseurUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use App\Cache\CacheTags;
use App\Services\TenantCacheService;

class TypeFournisseurController extends AbstractController
{
    private GetListTypeFournisseurUseCase $useCase;
    private TenantCacheService $cache;

    public function __construct(GetListTypeFournisseurUseCase $useCase, TenantCacheService $cache)
    {
        $this->useCase = $useCase;
        $this->cache = $cache;
    }

    #[Route('/api/type-fournisseurs', name: 'api_type_fournisseurs', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $cacheKey = 'api_type_fournisseurs_all';

        $data = $this->cache->get(
            $cacheKey,
            function () {
                $dtoList = $this->useCase->execute();
                return array_map(function ($dto) {
                    return [
                        'id'    => $dto->id,
                        'name'  => $dto->name,
                        'photo' => $dto->photo,
                    ];
                }, $dtoList);
            },
            3600,
            CacheTags::listTags(CacheTags::FOURNISSEURS)
        );

        return $this->json($data, JsonResponse::HTTP_OK);
    }
}

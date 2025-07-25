<?php
namespace App\Controller\CategorieMarqueApiController;

use App\Dto\CategorieMarqueInputDto;
use App\Dto\CategorieMarqueOutputDto;
use App\Services\TenantCacheService;
use App\UseCase\CategorieMarqueUseCase\GetAllCategoriesMarqueUseCase;
use App\UseCase\CategorieMarqueUseCase\CreateCategorieMarqueUseCase;
use App\UseCase\CategorieMarqueUseCase\UpdateCategorieMarqueUseCase;
use App\UseCase\CategorieMarqueUseCase\DeleteCategorieMarqueUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Cache\ItemInterface;

#[Route('/api/categories-marque')]
class CategorieMarqueApiController extends AbstractController
{
    public function __construct(
        private GetAllCategoriesMarqueUseCase $getAllCategoriesMarqueUseCase,
        private CreateCategorieMarqueUseCase $createCategorieMarqueUseCase,
        private UpdateCategorieMarqueUseCase $updateCategorieMarqueUseCase,
        private DeleteCategorieMarqueUseCase $deleteCategorieMarqueUseCase,
        private TenantCacheService $cache
    ) {}

    #[Route('', name: 'api_categorie_marque_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $cacheKey = 'categories_marque_all';
        $cacheTags = ['categories_marque'];

        $categoriesDto = $this->cache->get(
            $cacheKey,
            function (ItemInterface $item) {
                $categories = $this->getAllCategoriesMarqueUseCase->execute();
                return array_map(fn($cat) => new CategorieMarqueOutputDto($cat), $categories);
            },
            3600,
            $cacheTags
        );

        return $this->json($categoriesDto);
    }

    #[Route('', name: 'api_categorie_marque_create', methods: ['POST'])]
    public function create(#[MapRequestPayload] CategorieMarqueInputDto $dto): JsonResponse
    {
        $categorie = $this->createCategorieMarqueUseCase->execute($dto);
        return $this->json(new CategorieMarqueOutputDto($categorie), Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_categorie_marque_update', methods: ['PUT'])]
    public function update(int $id, #[MapRequestPayload] CategorieMarqueInputDto $dto): JsonResponse
    {
        $categorie = $this->updateCategorieMarqueUseCase->execute($id, $dto);
        return $this->json(new CategorieMarqueOutputDto($categorie));
    }

    #[Route('/{id}', name: 'api_categorie_marque_delete', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $this->deleteCategorieMarqueUseCase->execute($id);
        return $this->json(null, Response::HTTP_NO_CONTENT);
    }
}

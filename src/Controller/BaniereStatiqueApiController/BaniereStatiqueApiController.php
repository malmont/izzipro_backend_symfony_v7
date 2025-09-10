<?php
namespace App\Controller\BaniereStatiqueApiController;

// ... vos use statements
use App\Services\TenantCacheService;
use App\UseCase\BaniereStatiqueUseCase\GetAllBaniereStatiquesUseCase;
use App\UseCase\BaniereStatiqueUseCase\GetBaniereStatiqueByIdUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Cache\ItemInterface;

#[Route('/api/baniere-statiques')]
class BaniereStatiqueApiController extends AbstractController
{
    public function __construct(
        private GetAllBaniereStatiquesUseCase $getAllUseCase,
        private GetBaniereStatiqueByIdUseCase $getByIdUseCase,
        private TenantCacheService $cache 
    ) {}

    #[Route('', name: 'api_baniere_statique_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $locale = $request->getLocale();
        $cacheKey = 'bannieres_statiques_all_' . $locale;
        $baseImageUrl = $request->getSchemeAndHttpHost() . '/assets/uploads/slider';

        $dtos = $this->cache->get(
            $cacheKey,
            function (ItemInterface $item) use ($locale, $baseImageUrl) {
                $item->expiresAfter(3600);
                $item->tag(['bannieres_statiques_all']); 

                return $this->getAllUseCase->execute($locale, $baseImageUrl);
            }
        );

        return $this->json($dtos);
    }

    #[Route('/{id}', name: 'api_baniere_statique_get_one', methods: ['GET'])]
    public function getOne(int $id, Request $request): JsonResponse
    {
        $locale = $request->getLocale();
        $cacheKey = 'baniere_statique_' . $id . '_' . $locale;
        $baseImageUrl = $request->getSchemeAndHttpHost() . '/assets/uploads/slider';

        $dto = $this->cache->get(
            $cacheKey,
            function(ItemInterface $item) use ($id, $locale, $baseImageUrl) {
                $item->expiresAfter(3600);
                $item->tag(['bannieres_statiques_all', 'baniere_statique_' . $id]);
                return $this->getByIdUseCase->execute($id, $locale, $baseImageUrl);
            }
        );

        if (!$dto) {
            return $this->json(['message' => 'Bannière statique non trouvée'], Response::HTTP_NOT_FOUND);
        }
        
        return $this->json($dto);
    }
}
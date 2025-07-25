<?php
namespace App\Controller\ServiceOfferApiController;

use App\Dto\ServiceOfferInputDto;
use App\Dto\ServiceOfferOutputDto;
use App\Services\TenantCacheService;
use App\UseCase\ServiceOfferUseCase\GetAllServiceOffersUseCase;
use App\UseCase\ServiceOfferUseCase\CreateServiceOfferUseCase;
use App\UseCase\ServiceOfferUseCase\UpdateServiceOfferUseCase;
use App\UseCase\ServiceOfferUseCase\DeleteServiceOfferUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Cache\ItemInterface;

#[Route('/api/service-offers')]
class ServiceOfferApiController extends AbstractController
{
    public function __construct(
        private GetAllServiceOffersUseCase $getAllServiceOffersUseCase,
        private CreateServiceOfferUseCase $createServiceOfferUseCase,
        private UpdateServiceOfferUseCase $updateServiceOfferUseCase,
        private DeleteServiceOfferUseCase $deleteServiceOfferUseCase,
        private TenantCacheService $cache
    ) {}

    #[Route('', name: 'api_service_offer_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $cacheKey = 'service_offers_all';
        $cacheTags = ['service_offers'];
        $baseImageUrl = $request->getSchemeAndHttpHost() . '/uploads/services';

        $serviceOffersDto = $this->cache->get(
            $cacheKey,
            function (ItemInterface $item) use ($baseImageUrl) {
                $serviceOffers = $this->getAllServiceOffersUseCase->execute();
                return array_map(fn($offer) => new ServiceOfferOutputDto($offer, $baseImageUrl), $serviceOffers);
            },
            3600,
            $cacheTags
        );

        return $this->json($serviceOffersDto);
    }

    #[Route('', name: 'api_service_offer_create', methods: ['POST'])]
    public function create(#[MapRequestPayload] ServiceOfferInputDto $dto, Request $request): JsonResponse
    {
        $serviceOffer = $this->createServiceOfferUseCase->execute($dto);
        $baseImageUrl = $request->getSchemeAndHttpHost() . '/uploads/services';
        return $this->json(new ServiceOfferOutputDto($serviceOffer, $baseImageUrl), Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_service_offer_update', methods: ['PUT'])]
    public function update(int $id, #[MapRequestPayload] ServiceOfferInputDto $dto, Request $request): JsonResponse
    {
        $serviceOffer = $this->updateServiceOfferUseCase->execute($id, $dto);
        $baseImageUrl = $request->getSchemeAndHttpHost() . '/uploads/services';
        return $this->json(new ServiceOfferOutputDto($serviceOffer));
    }

    #[Route('/{id}', name: 'api_service_offer_delete', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $this->deleteServiceOfferUseCase->execute($id);
        return $this->json(null, Response::HTTP_NO_CONTENT);
    }
}

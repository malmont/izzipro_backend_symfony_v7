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
use App\UseCase\ServiceOfferUseCase\GetServiceOfferByIdUseCase;

#[Route('/api/service-offers')]
class ServiceOfferApiController extends AbstractController
{
    public function __construct(
        private GetAllServiceOffersUseCase $getAllServiceOffersUseCase,
        private CreateServiceOfferUseCase $createServiceOfferUseCase,
        private UpdateServiceOfferUseCase $updateServiceOfferUseCase,
        private DeleteServiceOfferUseCase $deleteServiceOfferUseCase,
        private TenantCacheService $cache,
        private GetServiceOfferByIdUseCase $getServiceOfferByIdUseCase
    ) {}

   #[Route('', name: 'api_service_offer_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $locale = $request->getLocale();
        $cacheKey = 'service_offers_all_' . $locale;
        $baseImageUrl = $request->getSchemeAndHttpHost() . '/assets/uploads/email-logos';

        $dtos = $this->cache->get(
            $cacheKey,
            function (ItemInterface $item) use ($locale, $baseImageUrl) {
                $item->expiresAfter(3600);
                $item->tag(['service_offers_all']);

                return $this->getAllServiceOffersUseCase->execute($baseImageUrl, $locale);
            }
        );

        return $this->json($dtos);
    }

    #[Route('/{id}', name: 'api_service_offer_get_one', methods: ['GET'])]
    public function getOne(int $id, Request $request): JsonResponse
    {
        $locale = $request->getLocale();
        $cacheKey = 'service_offer_' . $id . '_' . $locale;
        $baseImageUrl = $request->getSchemeAndHttpHost() . '/assets/uploads/email-logos';

        $dto = $this->cache->get(
            $cacheKey,
            function (ItemInterface $item) use ($id, $locale, $baseImageUrl) {
                $item->expiresAfter(3600);
                $item->tag(['service_offers_all', 'service_offer_' . $id]);

                return $this->getServiceOfferByIdUseCase->execute($id, $baseImageUrl, $locale);
            }
        );

        if (!$dto) {
            return $this->json(['message' => 'Offre de service non trouvée'], Response::HTTP_NOT_FOUND);
        }
        
        return $this->json($dto);
    }
    #[Route('', name: 'api_service_offer_create', methods: ['POST'])]
    public function create(#[MapRequestPayload] ServiceOfferInputDto $dto, Request $request): JsonResponse
    {
        $serviceOffer = $this->createServiceOfferUseCase->execute($dto);
        $baseImageUrl = $request->getSchemeAndHttpHost() . '/assets/uploads/email-logos';
        return $this->json(new ServiceOfferOutputDto($serviceOffer, $baseImageUrl), Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_service_offer_update', methods: ['PUT'])]
    public function update(int $id, #[MapRequestPayload] ServiceOfferInputDto $dto, Request $request): JsonResponse
    {
        $serviceOffer = $this->updateServiceOfferUseCase->execute($id, $dto);
        $baseImageUrl = $request->getSchemeAndHttpHost() . '/assets/uploads/email-logos';
        return $this->json(new ServiceOfferOutputDto($serviceOffer));
    }

    #[Route('/{id}', name: 'api_service_offer_delete', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $this->deleteServiceOfferUseCase->execute($id);
        return $this->json(null, Response::HTTP_NO_CONTENT);
    }
}

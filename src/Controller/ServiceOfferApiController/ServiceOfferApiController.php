<?php
namespace App\Controller\ServiceOfferApiController;

use App\Cache\CacheLocale;
use App\Cache\CacheTags;
use App\Dto\ServiceOfferInputDto;
use App\Dto\ServiceOfferOutputDto;
use App\Services\MediaUrlResolver;
use App\Services\TenantCacheService;
use App\UseCase\ServiceOfferUseCase\CreateServiceOfferUseCase;
use App\UseCase\ServiceOfferUseCase\DeleteServiceOfferUseCase;
use App\UseCase\ServiceOfferUseCase\GetAllServiceOffersUseCase;
use App\UseCase\ServiceOfferUseCase\GetServiceOfferByIdUseCase;
use App\UseCase\ServiceOfferUseCase\UpdateServiceOfferUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/service-offers')]
class ServiceOfferApiController extends AbstractController
{
    public function __construct(
        private GetAllServiceOffersUseCase $getAllServiceOffersUseCase,
        private CreateServiceOfferUseCase $createServiceOfferUseCase,
        private UpdateServiceOfferUseCase $updateServiceOfferUseCase,
        private DeleteServiceOfferUseCase $deleteServiceOfferUseCase,
        private TenantCacheService $cache,
        private GetServiceOfferByIdUseCase $getServiceOfferByIdUseCase,
        private ?MediaUrlResolver $mediaUrlResolver = null
    ) {}

   #[Route('', name: 'api_service_offer_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $locale = CacheLocale::normalize($request->getLocale());
        $cacheKey = 'service_offers_all_' . $locale;
        $baseImageUrl = $this->mediaUrlResolver?->getEmailLogosBaseUrl($request->getSchemeAndHttpHost())
            ?? ($request->getSchemeAndHttpHost() . '/assets/uploads/email-logos');

        // une prestation porte sa famille (familyId) : elle suit aussi les écritures des familles
        $dtos = $this->cache->get(
            $cacheKey,
            fn () => $this->getAllServiceOffersUseCase->execute($baseImageUrl, $locale),
            3600,
            [...CacheTags::listTags(CacheTags::SERVICE_OFFERS), ...CacheTags::listTags(CacheTags::SERVICE_FAMILIES)]
        );

        return $this->json($dtos);
    }

    #[Route('/{id}', name: 'api_service_offer_get_one', methods: ['GET'])]
    public function getOne(int $id, Request $request): JsonResponse
    {
        $locale = CacheLocale::normalize($request->getLocale());
        $cacheKey = 'service_offer_' . $id . '_' . $locale;
        $baseImageUrl = $this->mediaUrlResolver?->getEmailLogosBaseUrl($request->getSchemeAndHttpHost())
            ?? ($request->getSchemeAndHttpHost() . '/assets/uploads/email-logos');

        $dto = $this->cache->get(
            $cacheKey,
            fn () => $this->getServiceOfferByIdUseCase->execute($id, $baseImageUrl, $locale),
            3600,
            [...CacheTags::itemTags(CacheTags::SERVICE_OFFERS, $id), ...CacheTags::listTags(CacheTags::SERVICE_FAMILIES)]
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
        $baseImageUrl = $this->mediaUrlResolver?->getEmailLogosBaseUrl($request->getSchemeAndHttpHost())
            ?? ($request->getSchemeAndHttpHost() . '/assets/uploads/email-logos');
        // langue passée au DTO depuis le 28/10/2026 : sans elle, la réponse tombait en 500 après l'écriture
        return $this->json(new ServiceOfferOutputDto($serviceOffer, $baseImageUrl, $request->getLocale()), Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_service_offer_update', methods: ['PUT'])]
    public function update(int $id, #[MapRequestPayload] ServiceOfferInputDto $dto, Request $request): JsonResponse
    {
        $serviceOffer = $this->updateServiceOfferUseCase->execute($id, $dto);
        $baseImageUrl = $this->mediaUrlResolver?->getEmailLogosBaseUrl($request->getSchemeAndHttpHost())
            ?? ($request->getSchemeAndHttpHost() . '/assets/uploads/email-logos');
        return $this->json(new ServiceOfferOutputDto($serviceOffer, $baseImageUrl, $request->getLocale()));
    }

    #[Route('/{id}', name: 'api_service_offer_delete', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $this->deleteServiceOfferUseCase->execute($id);
        return $this->json(null, Response::HTTP_NO_CONTENT);
    }
}

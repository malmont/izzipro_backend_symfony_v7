<?php

namespace App\Controller\AdressController;

use App\Dto\AddressSuggestionInputDto;
use App\Dto\AddressDetailsInputDto;
use App\UseCase\AdressUseCase\SuggestAddressUseCase;
use App\UseCase\AdressUseCase\GetAddressDetailsUseCase;
use App\Services\AdressService\AddressAutocompleteUnavailableException;
use App\Services\AdressService\PlaceNotFoundException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

#[Route('/api/adresses')]
class AddressAutocompleteController extends AbstractController
{
    public function __construct(
        private SuggestAddressUseCase     $suggestUseCase,
        private GetAddressDetailsUseCase  $detailsUseCase,
        private readonly ?\App\Services\EmailConfigurationService\EmailLocale $locale = null // langue du client (03/11/2026) : ?locale= fr|en, sinon celle du site
    ) {}

    #[Route('/autocomplete', methods: ['GET'])]
    public function suggest(Request $request): JsonResponse
    {
        try {
            $dto = new AddressSuggestionInputDto($request->query->all());
            $results = $this->suggestUseCase->execute($dto, $this->locale?->fromRequest($request));
            return $this->json($results);
        } catch (\Throwable $e) {
            return $this->json([]);
        }
    }

    #[Route('/details', methods: ['GET'])]
    /**
     * Détails d'une adresse par place_id. Jamais 500 (31/10/2026) : 400 sans place_id, 404 identifiant inconnu
     * (PLACE_NOT_FOUND) ou aucune clé Google Places sur le site (AUTOCOMPLETE_NOT_CONFIGURED), 503 API injoignable.
     */
    public function details(Request $request): JsonResponse
    {
        $dto = new AddressDetailsInputDto($request->query->all());
        try {
            return $this->json($this->detailsUseCase->execute($dto, $this->locale?->fromRequest($request)));
        } catch (BadRequestHttpException $e) {
            return $this->json(['error' => 'PLACE_ID_REQUIRED', 'message' => $e->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
        } catch (PlaceNotFoundException $e) {
            return $this->json(['error' => 'PLACE_NOT_FOUND', 'message' => 'Adresse introuvable pour cet identifiant'], JsonResponse::HTTP_NOT_FOUND);
        } catch (AddressAutocompleteUnavailableException $e) {
            // 404 et non 503 : nginx remplace le corps de toute réponse 5xx par un message neutre, le code d'erreur doit arriver au client
            return $this->json(['error' => 'AUTOCOMPLETE_NOT_CONFIGURED', 'message' => 'Autocomplétion non configurée sur ce site'], JsonResponse::HTTP_NOT_FOUND);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'AUTOCOMPLETE_UNAVAILABLE', 'message' => 'Service d\'adresses indisponible'], JsonResponse::HTTP_SERVICE_UNAVAILABLE);
        }
    }
}

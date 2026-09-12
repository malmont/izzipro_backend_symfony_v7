<?php

namespace App\Controller\AdressController;

use App\Dto\AddressSuggestionInputDto;
use App\Dto\AddressDetailsInputDto;
use App\UseCase\AdressUseCase\SuggestAddressUseCase;
use App\UseCase\AdressUseCase\GetAddressDetailsUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

#[Route('/api/adresses')]
class AddressAutocompleteController extends AbstractController
{
    public function __construct(
        private SuggestAddressUseCase     $suggestUseCase,
        private GetAddressDetailsUseCase  $detailsUseCase
    ) {}

    #[Route('/autocomplete', methods: ['GET'])]
    public function suggest(Request $request): JsonResponse
    {
        try {
            $dto = new AddressSuggestionInputDto($request->query->all());
            $results = $this->suggestUseCase->execute($dto);
            return $this->json($results);
        } catch (\Throwable $e) {
            return $this->json([]);
        }
    }

    #[Route('/details', methods: ['GET'])]
    public function details(Request $request): JsonResponse
    {
        $dto    = new AddressDetailsInputDto($request->query->all());
        $result = $this->detailsUseCase->execute($dto);

        return $this->json($result);
    }
}

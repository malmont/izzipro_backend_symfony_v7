<?php

namespace App\Controller\CurrencyController;

use App\UseCase\Currency\GetAvailableCurrencies;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/currencies', name: 'api_currencies_get', methods: ['GET'])]
class CurrencyController extends AbstractController
{
    public function __invoke(GetAvailableCurrencies $useCase): JsonResponse
    {
        $data = $useCase->execute();

        return $this->json($data)
            ->setSharedMaxAge(3600);
    }
}

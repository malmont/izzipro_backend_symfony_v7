<?php

namespace App\Controller\VehicleProductController;

use App\UseCase\VehicleProductUseCase\GetVehicleProductUseCase;
use App\UseCase\VehicleProductUseCase\ListVehicleProductsUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Lecture publique des produits véhicules par la boutique (lot APIP-RETRAIT, 20/11/2026) : reprend, au même chemin, les deux
 * opérations d'API Platform que la boutique appelait. Tableau JSON simple, 24 par page, ordre croissant des identifiants ;
 * `locale` accepté et sans effet, comme avant. Aucune écriture : les anciennes routes d'écriture n'existent plus (404/405).
 * Hors pare-feu JWT et lecture publique par security.yaml (chemin `vehicle_products`), comme avant.
 */
class VehicleProductController extends AbstractController
{
    /** Les nombres à virgule gardent leur « .0 », comme les écrivait API Platform (`price` : 40000.0) */
    private const JSON = ['json_encode_options' => JsonResponse::DEFAULT_ENCODING_OPTIONS | \JSON_PRESERVE_ZERO_FRACTION];

    public function __construct(
        private ListVehicleProductsUseCase $list,
        private GetVehicleProductUseCase $get,
    ) {
    }

    #[Route('/api/vehicle_products', name: 'api_vehicle_products_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $page = $request->query->get('page', '1');
        if (!ctype_digit((string) $page) || (int) $page < 1) {
            throw new BadRequestHttpException('Page should not be less than 1');
        }
        // au-delà de toute page possible : une page vide, comme avant, sans calculer un décalage démesuré
        if (strlen((string) $page) > 6) {
            return $this->json([], context: self::JSON);
        }

        return $this->json($this->list->execute((int) $page), context: self::JSON);
    }

    #[Route('/api/vehicle_products/{id}', name: 'api_vehicle_products_get', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function item(int $id): JsonResponse
    {
        return $this->json($this->get->execute($id), context: self::JSON);
    }
}

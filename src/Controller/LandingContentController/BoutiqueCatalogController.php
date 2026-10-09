<?php

namespace App\Controller\LandingContentController;

use App\UseCase\LandingContentUseCase\LandingContentException;
use App\UseCase\LandingContentUseCase\ManageBoutiqueCatalogUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Données de la boutique éditées depuis la page (09/10/2026, ROLE_ADMIN du site) : créations, suppressions et ordre.
 * Les modifications champ par champ passent par PATCH /api/{ressource}/{id} (LandingContentController).
 * ?variantId= : réponse = configuration de personnalisation de cette variante (valeurs d'option).
 */
#[IsGranted('ROLE_ADMIN')]
class BoutiqueCatalogController extends AbstractController
{
    public function __construct(private readonly ManageBoutiqueCatalogUseCase $catalog)
    {
    }

    #[Route('/api/products/{id}/variants', name: 'api_product_variant_create', methods: ['POST'], priority: 20, requirements: ['id' => '\d+'])]
    public function createVariant(int $id, Request $request): JsonResponse
    {
        return $this->handle(fn () => $this->json($this->catalog->createVariant($id, $this->body($request), $this->locale($request)), 201));
    }

    #[Route('/api/product-variants/{id}', name: 'api_product_variant_delete', methods: ['DELETE'], priority: 20, requirements: ['id' => '\d+'])]
    public function deleteVariant(int $id, Request $request): JsonResponse
    {
        return $this->handle(function () use ($id, $request) {
            $this->catalog->deleteVariant($id, $this->locale($request));

            return new JsonResponse(null, 204);
        });
    }

    #[Route('/api/colors', name: 'api_color_create', methods: ['POST'])]
    public function createColor(Request $request): JsonResponse
    {
        return $this->handle(fn () => $this->json($this->catalog->createColor($this->body($request), $this->locale($request)), 201));
    }

    #[Route('/api/sizes', name: 'api_size_create', methods: ['POST'])]
    public function createSize(Request $request): JsonResponse
    {
        return $this->handle(fn () => $this->json($this->catalog->createSize($this->body($request), $this->locale($request)), 201));
    }

    #[Route('/api/customization-options/{id}/values', name: 'api_customization_value_create', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function addValue(int $id, Request $request): JsonResponse
    {
        return $this->handle(fn () => $this->json($this->catalog->addValue($id, $this->body($request), $this->locale($request), $request->getSchemeAndHttpHost(), $this->variantId($request)), 201));
    }

    #[Route('/api/customization-values/{id}', name: 'api_customization_value_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function deleteValue(int $id, Request $request): JsonResponse
    {
        return $this->handle(function () use ($id, $request) {
            $config = $this->catalog->deleteValue($id, $this->locale($request), $request->getSchemeAndHttpHost(), $this->variantId($request));

            return $config === null ? new JsonResponse(null, 204) : $this->json($config);
        });
    }

    #[Route('/api/product-variants/{id}/customization-combinations', name: 'api_customization_combination_create', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function addCombination(int $id, Request $request): JsonResponse
    {
        return $this->handle(fn () => $this->json($this->catalog->addCombination($id, $this->body($request), $this->locale($request)), 201));
    }

    #[Route('/api/customization-combinations/{id}', name: 'api_customization_combination_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function deleteCombination(int $id, Request $request): JsonResponse
    {
        return $this->handle(fn () => $this->json($this->catalog->deleteCombination($id, $this->locale($request))));
    }

    #[Route('/api/features', name: 'api_feature_create', methods: ['POST'])]
    public function createFeature(Request $request): JsonResponse
    {
        return $this->handle(fn () => $this->json($this->catalog->createFeature($this->body($request), $this->locale($request), $request->getSchemeAndHttpHost()), 201));
    }

    #[Route('/api/features/order', name: 'api_feature_order', methods: ['PUT'], priority: 10)]
    public function reorderFeatures(Request $request): JsonResponse
    {
        return $this->handle(fn () => $this->json($this->catalog->reorderFeatures($this->body($request), $this->locale($request), $request->getSchemeAndHttpHost())));
    }

    #[Route('/api/features/{id}', name: 'api_feature_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function deleteFeature(int $id, Request $request): JsonResponse
    {
        return $this->handle(function () use ($id, $request) {
            $this->catalog->deleteFeature($id, $this->locale($request));

            return new JsonResponse(null, 204);
        });
    }

    private function body(Request $request): mixed
    {
        if (strlen($request->getContent()) > LandingContentController::MAX_BODY_BYTES) {
            throw new LandingContentException(413, 'Corps de la requête trop volumineux.');
        }
        try {
            return json_decode($request->getContent() ?: '{}', false, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new LandingContentException(400, 'Corps JSON invalide.');
        }
    }

    private function locale(Request $request): string
    {
        return (string) $request->query->get('locale', 'fr');
    }

    private function variantId(Request $request): ?int
    {
        $id = $request->query->get('variantId');

        return is_numeric($id) ? (int) $id : null;
    }

    private function handle(callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (LandingContentException $e) {
            return $this->json(array_filter(['error' => $e->getMessage(), 'errors' => $e->errors]), $e->getStatusCode());
        }
    }
}

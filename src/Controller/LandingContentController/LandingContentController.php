<?php

namespace App\Controller\LandingContentController;

use App\UseCase\LandingContentUseCase\LandingContentException;
use App\UseCase\LandingContentUseCase\ManagePresentationGroupUseCase;
use App\UseCase\LandingContentUseCase\PatchLandingContentUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Modification des contenus de section depuis l'éditeur des landing pages (ROLE_ADMIN du site) : champs envoyés
 * seulement, liste blanche par ressource (LandingContentSpec). Les GET, POST, PUT et DELETE existants sont inchangés.
 */
#[IsGranted('ROLE_ADMIN')]
class LandingContentController extends AbstractController
{
    public const MAX_BODY_BYTES = 262144;

    public function __construct(
        private readonly PatchLandingContentUseCase $patchUseCase,
        private readonly ManagePresentationGroupUseCase $groupUseCase
    ) {
    }

    /** Crée une présentation dans le groupe : champs de PATCH /api/presentations (titre obligatoire), « after » facultatif */
    #[Route('/api/presentation-groups/{id}/presentations', name: 'api_presentation_group_add', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function addPresentation(int $id, Request $request): JsonResponse
    {
        return $this->handle(fn () => $this->json($this->groupUseCase->add($id, $this->body($request), $this->locale($request), $request->getSchemeAndHttpHost()), 201));
    }

    /** Retire la présentation du groupe ; supprimée si elle n'appartient plus à aucun groupe */
    #[Route('/api/presentation-groups/{id}/presentations/{presentationId}', name: 'api_presentation_group_remove', methods: ['DELETE'], requirements: ['id' => '\d+', 'presentationId' => '\d+'])]
    public function removePresentation(int $id, int $presentationId, Request $request): JsonResponse
    {
        return $this->handle(fn () => $this->json($this->groupUseCase->remove($id, $presentationId, $this->locale($request), $request->getSchemeAndHttpHost())));
    }

    /** { order: [identifiants] } : toutes les présentations du groupe, chacune une fois */
    #[Route('/api/presentation-groups/{id}/presentations/order', name: 'api_presentation_group_order', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function reorder(int $id, Request $request): JsonResponse
    {
        return $this->handle(fn () => $this->json($this->groupUseCase->reorder($id, $this->body($request), $this->locale($request), $request->getSchemeAndHttpHost())));
    }

    private function body(Request $request): mixed
    {
        if (strlen($request->getContent()) > self::MAX_BODY_BYTES) {
            throw new LandingContentException(413, 'Corps de la requête trop volumineux.');
        }
        try {
            return json_decode($request->getContent(), false, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new LandingContentException(400, 'Corps JSON invalide.');
        }
    }

    private function locale(Request $request): string
    {
        return (string) $request->query->get('locale', 'fr');
    }

    private function handle(callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (LandingContentException $e) {
            return $this->json(array_filter(['error' => $e->getMessage(), 'errors' => $e->errors]), $e->getStatusCode());
        }
    }

    // priority : passe avant le PATCH générique d'API Platform sur /api/products/{id} (merge-patch), qui n'applique aucune règle
    #[Route('/api/{resource}/{id}', name: 'api_landing_content_patch', methods: ['PATCH'], priority: 10,
        requirements: ['resource' => 'presentations|presentation-groups|baniere-statiques|bannieres|videos|service-offers|products|category|homeslider|explore-cards|product-variants|customization-options|customization-values|customization-combinations|subscription-plans|features', 'id' => '\d+'])]
    public function patch(string $resource, int $id, Request $request): JsonResponse
    {
        try {
            if (strlen($request->getContent()) > self::MAX_BODY_BYTES) {
                throw new LandingContentException(413, 'Corps de la requête trop volumineux.');
            }
            try {
                $body = json_decode($request->getContent(), false, 8, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new LandingContentException(400, 'Corps JSON invalide.');
            }
            $variantId = $request->query->get('variantId');
            $output = $this->patchUseCase->execute($resource, $id, $body, (string) $request->query->get('locale', 'fr'), $request->getSchemeAndHttpHost(),
                is_numeric($variantId) ? (int) $variantId : null);
        } catch (LandingContentException $e) {
            return $this->json(array_filter(['error' => $e->getMessage(), 'errors' => $e->errors]), $e->getStatusCode());
        }

        return $this->json($output);
    }
}

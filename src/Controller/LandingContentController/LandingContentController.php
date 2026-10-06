<?php

namespace App\Controller\LandingContentController;

use App\UseCase\LandingContentUseCase\LandingContentException;
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

    public function __construct(private readonly PatchLandingContentUseCase $patchUseCase)
    {
    }

    #[Route('/api/{resource}/{id}', name: 'api_landing_content_patch', methods: ['PATCH'],
        requirements: ['resource' => 'presentations|presentation-groups|baniere-statiques|bannieres|videos|service-offers', 'id' => '\d+'])]
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
            $output = $this->patchUseCase->execute($resource, $id, $body, (string) $request->query->get('locale', 'fr'), $request->getSchemeAndHttpHost());
        } catch (LandingContentException $e) {
            return $this->json(array_filter(['error' => $e->getMessage(), 'errors' => $e->errors]), $e->getStatusCode());
        }

        return $this->json($output);
    }
}

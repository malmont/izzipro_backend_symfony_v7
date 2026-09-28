<?php

namespace App\Controller\LandingAiController;

use App\Dto\LandingAiComposeInputDto;
use App\Services\LandingAiService\LandingAiException;
use App\UseCase\LandingAiUseCase\ComposeLandingSectionUseCase;
use App\UseCase\LandingAiUseCase\GetLandingAiUsageUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Assistant IA de l'éditeur de landing pages (ROLE_ADMIN du tenant, voir security.yaml).
 */
#[Route('/api/landingpage-ai')]
class LandingAiController extends AbstractController
{
    public function __construct(
        private readonly ComposeLandingSectionUseCase $composeUseCase,
        private readonly GetLandingAiUsageUseCase $usageUseCase
    ) {
    }

    #[Route('/compose', name: 'api_landingpage_ai_compose', methods: ['POST'])]
    public function compose(Request $request): JsonResponse
    {
        try {
            if (strlen($request->getContent()) > LandingAiComposeInputDto::MAX_BODY_BYTES) {
                throw LandingAiException::badRequest('Requête trop volumineuse.');
            }
            try {
                $body = json_decode($request->getContent(), false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            } catch (\JsonException) {
                throw LandingAiException::badRequest('Corps JSON invalide.');
            }

            $result = $this->composeUseCase->execute(LandingAiComposeInputDto::fromRequestBody($body), $this->getUser()?->getUserIdentifier());

            // Encodage direct : la composition garde ses {} et ses nombres décimaux (1.0)
            return new JsonResponse(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR), 200, [], true);
        } catch (LandingAiException $e) {
            return new JsonResponse($e->toArray(), $e->getStatusCode(), $e->getHeaders());
        }
    }

    #[Route('/usage', name: 'api_landingpage_ai_usage', methods: ['GET'])]
    public function usage(): JsonResponse
    {
        return $this->json($this->usageUseCase->execute());
    }
}

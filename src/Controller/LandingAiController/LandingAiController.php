<?php

namespace App\Controller\LandingAiController;

use App\Dto\LandingAiComposeInputDto;
use App\Services\LandingAiService\LandingAiComposeRunner;
use App\Services\LandingAiService\LandingAiException;
use App\UseCase\LandingAiUseCase\ComposeLandingSectionUseCase;
use App\UseCase\LandingAiUseCase\GetLandingAiJobUseCase;
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
        private readonly GetLandingAiUsageUseCase $usageUseCase,
        private readonly GetLandingAiJobUseCase $jobUseCase
    ) {
    }

    #[Route('/compose', name: 'api_landingpage_ai_compose', methods: ['POST'])]
    public function compose(Request $request): JsonResponse
    {
        try {
            if (strlen($request->getContent()) > LandingAiComposeInputDto::maxBodyBytes()) {
                throw new LandingAiException(413, 'Requête trop volumineuse', sprintf('Le corps de la requête dépasse %d Mo (images comprises).', intdiv(LandingAiComposeInputDto::maxBodyBytes(), 1048576)));
            }
            try {
                $body = json_decode($request->getContent(), false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            } catch (\JsonException) {
                throw LandingAiException::badRequest('Corps JSON invalide.');
            }

            $result = $this->composeUseCase->execute(LandingAiComposeInputDto::fromRequestBody($body), $this->getUser()?->getUserIdentifier());

            // 200 : proposition (edit, create) ; 202 : tâche de fond (page, images) à lire sur /jobs/{jobId}
            return new JsonResponse(LandingAiComposeRunner::encode($result->body), $result->status, $result->headers, true);
        } catch (LandingAiException $e) {
            return new JsonResponse($e->toArray(), $e->getStatusCode(), $e->getHeaders());
        }
    }

    #[Route('/jobs/{jobId}', name: 'api_landingpage_ai_job', methods: ['GET'])]
    public function job(string $jobId): JsonResponse
    {
        try {
            return new JsonResponse($this->jobUseCase->execute($jobId), 200, [], true);
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

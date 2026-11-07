<?php

namespace App\Controller\SiteAssistantController;

use App\Services\BoutiqueSettingsService\BoutiqueSettingsException;
use App\Services\EntrepriseService\EntrepriseForbiddenException;
use App\Services\EntrepriseService\EntrepriseValidationException;
use App\Services\FeatureAccess\FeatureCatalog;
use App\Services\FeatureAccess\SiteFeaturesValidationException;
use App\Services\LandingAiService\LandingAiException;
use App\Services\LandingAiService\LandingAiQuotaService;
use App\Services\Platform\SiteReadinessService;
use App\UseCase\SiteAssistantUseCase\ChatWithSiteAssistantUseCase;
use App\UseCase\SiteAssistantUseCase\ConfirmSiteAssistantActionUseCase;
use App\UseCase\SiteAssistantUseCase\SiteAssistantValidationException;
use App\UseCase\SiteFeaturesUseCase\SiteFeatureRequestException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Assistant IA de configuration (console de pilotage, 05/11/2026), administrateur du site.
 * POST /api/site/assistant { messages: [{ role: user | assistant, content }], locale? } → { reply, actions: [{ id, tool, input, summary }],
 * links: [{ label, screen }], readiness, credits, usage } ; 422 VALIDATION_FAILED, 402 AI_CREDITS_EXHAUSTED, 429 AI_TOO_MANY_FAILURES,
 * 503 AI_UNAVAILABLE. POST /api/site/assistant/actions/{id}/confirm → { tool, input, result, readiness } ; 404 ACTION_NOT_FOUND
 * (inconnue ou expirée après 15 min), 422 / 403 / 409 comme PUT /api/site/features et la demande de fonctionnalité.
 * GET /api/site/assistant/credits → crédits IA du mois. Contrat : docs/console-pilotage.md.
 */
#[Route('/api/site/assistant')]
#[IsGranted('ROLE_ADMIN')]
class SiteAssistantController extends AbstractController
{
    public function __construct(private readonly ChatWithSiteAssistantUseCase $chat, private readonly ConfirmSiteAssistantActionUseCase $confirm, private readonly SiteReadinessService $readiness, private readonly LandingAiQuotaService $quota)
    {
    }

    #[Route('', name: 'api_site_assistant_chat', methods: ['POST'])]
    public function chat(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true);
        try {
            $result = $this->chat->execute(is_array($body) ? $body : [], $this->getUser()?->getUserIdentifier(), $this->isGranted('ROLE_SUPER_ADMIN'));
        } catch (SiteAssistantValidationException $e) {
            return $this->json(['error' => 'VALIDATION_FAILED', 'code' => 'VALIDATION_FAILED', 'message' => $e->getMessage(), 'errors' => $e->errors], 422);
        } catch (LandingAiException $e) {
            $code = match ($e->getStatusCode()) { 402 => 'AI_CREDITS_EXHAUSTED', 429 => 'AI_TOO_MANY_FAILURES', default => 'AI_UNAVAILABLE' };

            return $this->json(['error' => $code, 'code' => $code, 'message' => $e->getMessage()], $e->getStatusCode(), $e->getHeaders());
        }

        return $this->json($result + ['readiness' => $this->readiness->forCurrentSite(), 'credits' => $this->quota->credits()]);
    }

    #[Route('/actions/{id}/confirm', name: 'api_site_assistant_confirm', methods: ['POST'], requirements: ['id' => '[a-f0-9]{16}'])]
    public function confirm(string $id, Request $request): JsonResponse
    {
        try {
            $result = $this->confirm->execute($id, $request->getSchemeAndHttpHost(), $this->getUser()?->getUserIdentifier());
        } catch (\OutOfBoundsException $e) {
            return $this->json(['error' => 'ACTION_NOT_FOUND', 'code' => 'ACTION_NOT_FOUND', 'message' => $e->getMessage()], 404);
        } catch (SiteFeaturesValidationException|EntrepriseValidationException|BoutiqueSettingsException $e) {
            $errors = array_map(fn (array $err) => ['path' => FeatureCatalog::keyForStorage($err['path']), 'message' => $err['message']], $e->errors ?? []);

            return $this->json(['error' => 'VALIDATION_FAILED', 'code' => 'VALIDATION_FAILED', 'message' => $e->getMessage(), 'errors' => $errors], 422);
        } catch (EntrepriseForbiddenException $e) {
            return $this->json(['error' => $e::CODE, 'code' => $e::CODE, 'message' => $e->getMessage(), 'fields' => $e->fields], 403);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => 'VALIDATION_FAILED', 'code' => 'VALIDATION_FAILED', 'message' => $e->getMessage(), 'errors' => [['path' => '', 'message' => $e->getMessage()]]], 422);
        } catch (SiteFeatureRequestException $e) {
            return $this->json(['error' => $e->code409, 'code' => $e->code409, 'message' => $e->getMessage()], 409);
        } catch (\DomainException $e) {
            return $this->json(['error' => 'DOMAIN_TAKEN', 'code' => 'DOMAIN_TAKEN', 'message' => $e->getMessage()], 409);
        }

        return $this->json($result + ['readiness' => $this->readiness->forCurrentSite()]);
    }

    #[Route('/credits', name: 'api_site_assistant_credits', methods: ['GET'])]
    public function credits(): JsonResponse
    {
        return $this->json(['credits' => $this->quota->credits()]);
    }
}

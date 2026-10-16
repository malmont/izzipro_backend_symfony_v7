<?php

namespace App\MemoiresVivantes\Controller\Admin;

use App\MemoiresVivantes\Dto\AiQuotaSettingsInputDto;
use App\MemoiresVivantes\Dto\AiQuotaSettingsOutputDto;
use App\MemoiresVivantes\Services\AiQuotaService;
use App\MemoiresVivantes\UseCase\Quota\GetAiQuotaUsageUseCase;
use App\MemoiresVivantes\UseCase\Quota\UpdateAiQuotaSettingsUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Réglage des quotas IA de Mémoires Vivantes par l'administration du web (16/10/2026) : lecture et modification des
 * réglages du site, consommation du jour par utilisateur. ROLE_ADMIN (access_control `^/api/memoires/admin/` et
 * #[IsGranted]). Contrat : docs/memoires-vivantes.md, « Quotas des appels IA ».
 */
#[Route('/api/memoires/admin/ai-quotas')]
#[IsGranted('ROLE_ADMIN')]
class AiQuotaAdminController extends AbstractController
{
    public function __construct(
        private readonly AiQuotaService $quotas,
        private readonly UpdateAiQuotaSettingsUseCase $update,
        private readonly GetAiQuotaUsageUseCase $usage
    ) {}

    #[Route('', methods: ['GET'])]
    public function show(): JsonResponse
    {
        return $this->json(AiQuotaSettingsOutputDto::toArray($this->quotas->settings()));
    }

    /** Champs facultatifs (absent = inchangé) ; 422 VALIDATION_FAILED avec le détail par champ */
    #[Route('', methods: ['PUT'])]
    public function update(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'VALIDATION_FAILED', 'message' => 'Corps JSON invalide', 'errors' => [['path' => '', 'message' => 'corps JSON invalide']]], 422);
        }
        $dto = new AiQuotaSettingsInputDto($data);
        try {
            $settings = $this->update->execute($dto);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => 'VALIDATION_FAILED', 'message' => $e->getMessage(), 'errors' => $dto->errors], 422);
        }

        return $this->json(AiQuotaSettingsOutputDto::toArray($settings));
    }

    /** ?date=AAAA-MM-JJ (jour du site, aujourd'hui par défaut) → {date, timezone, from, to, users: [{userId, email, name, transcriptions, improvements}], limits} */
    #[Route('/usage', methods: ['GET'])]
    public function usage(Request $request): JsonResponse
    {
        $date = $request->query->get('date');
        try {
            return $this->json($this->usage->execute($date !== null && $date !== '' ? (string) $date : null));
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => 'VALIDATION_FAILED', 'message' => $e->getMessage(), 'errors' => [['path' => 'date', 'message' => $e->getMessage()]]], 422);
        }
    }
}

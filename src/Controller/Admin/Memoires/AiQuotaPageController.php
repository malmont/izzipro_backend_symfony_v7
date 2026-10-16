<?php

namespace App\Controller\Admin\Memoires;

use App\MemoiresVivantes\Dto\AiQuotaSettingsInputDto;
use App\MemoiresVivantes\Dto\AiQuotaSettingsOutputDto;
use App\MemoiresVivantes\Services\AiQuotaService;
use App\MemoiresVivantes\UseCase\Quota\GetAiQuotaUsageUseCase;
use App\MemoiresVivantes\UseCase\Quota\UpdateAiQuotaSettingsUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Écran EasyAdmin « Quotas IA Mémoires Vivantes » (16/10/2026) : réglages du site (transcriptions et améliorations
 * par utilisateur et par jour, durée maximale d'un enregistrement, application aux livres web, fuseau horaire) et
 * consommation d'un jour par utilisateur. Formulaire POST protégé par jeton CSRF. ROLE_ADMIN par access_control (^/admin).
 */
class AiQuotaPageController extends AbstractController
{
    private const CSRF_ID = 'admin_mv_ai_quotas';

    public function __construct(
        private readonly AiQuotaService $quotas,
        private readonly UpdateAiQuotaSettingsUseCase $update,
        private readonly GetAiQuotaUsageUseCase $usage
    ) {}

    #[Route('/admin/memoires/ai-quotas', name: 'admin_mv_ai_quotas', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $date = $request->query->get('date');
        try {
            $usage = $this->usage->execute($date !== null && $date !== '' ? (string) $date : null);
        } catch (\InvalidArgumentException) {
            $this->addFlash('danger', 'Date invalide : format AAAA-MM-JJ attendu.');
            $usage = $this->usage->execute(null);
        }

        return $this->render('admin/memoires/ai_quotas/index.html.twig', [
            'settings' => AiQuotaSettingsOutputDto::toArray($this->quotas->settings()),
            'usage' => $usage,
            'timezones' => \DateTimeZone::listIdentifiers(),
            'csrf_id' => self::CSRF_ID,
        ]);
    }

    #[Route('/admin/memoires/ai-quotas', name: 'admin_mv_ai_quotas_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Jeton de sécurité invalide, recommencez.');
        } else {
            $dto = new AiQuotaSettingsInputDto([
                'transcriptionsPerDay' => $request->request->get('transcriptionsPerDay'),
                'improvementsPerDay' => $request->request->get('improvementsPerDay'),
                'regenerationsPerDay' => $request->request->get('regenerationsPerDay'),
                'maxAudioSeconds' => $request->request->get('maxAudioSeconds'),
                'applyToWebBooks' => $request->request->getBoolean('applyToWebBooks') ? '1' : '0',
                'timezone' => $request->request->get('timezone'),
            ]);
            try {
                $this->update->execute($dto);
                $this->addFlash('success', 'Quotas enregistrés.');
            } catch (\InvalidArgumentException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        }
        $tenant = $request->query->get('tenant');

        return $this->redirectToRoute('admin_mv_ai_quotas', $tenant ? ['tenant' => $tenant] : []);
    }
}

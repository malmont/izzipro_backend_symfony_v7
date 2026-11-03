<?php

namespace App\Controller\Admin\Memoires;

use App\MemoiresVivantes\Dto\AiQuotaSettingsInputDto;
use App\MemoiresVivantes\Dto\AiQuotaSettingsOutputDto;
use App\MemoiresVivantes\Entity\AiQuotaSetting;
use App\MemoiresVivantes\Services\AiQuotaService;
use App\MemoiresVivantes\UseCase\Quota\GetAiQuotaUsageUseCase;
use App\MemoiresVivantes\UseCase\Quota\UpdateAiQuotaSettingsUseCase;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Écran EasyAdmin « Quotas IA Mémoires Vivantes » (16/10/2026) : réglages du site (transcriptions et améliorations
 * par utilisateur et par jour, durée maximale d'un enregistrement, application aux livres web, fuseau horaire) et
 * consommation d'un jour par utilisateur. Formulaire POST protégé par jeton CSRF. ROLE_ADMIN par access_control (^/admin) ;
 * plafonds réservés à ROLE_SUPER_ADMIN (seul le fuseau horaire reste modifiable par ROLE_ADMIN).
 */
class AiQuotaPageController extends AbstractController
{
    private const CSRF_ID = 'admin_mv_ai_quotas';
    private const QUOTA_FIELDS = [
        'transcriptionsPerDay',
        'transcriptions_per_day',
        'improvementsPerDay',
        'improvements_per_day',
        'regenerationsPerDay',
        'regenerations_per_day',
        'maxAudioSeconds',
        'max_audio_seconds',
        'applyToWebBooks',
        'apply_to_web_books',
    ];

    public function __construct(
        private readonly AiQuotaService $quotas,
        private readonly UpdateAiQuotaSettingsUseCase $update,
        private readonly GetAiQuotaUsageUseCase $usage,
        private readonly TenantEntityManagerProvider $emProvider
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
            if (!$this->isGranted('ROLE_SUPER_ADMIN')) {
                foreach (self::QUOTA_FIELDS as $field) {
                    if ($request->request->has($field)) {
                        throw $this->createAccessDeniedException('Modification des plafonds IA réservée au super administrateur.');
                    }
                }
                $current = $this->currentQuotasFromDb();
                $dto = new AiQuotaSettingsInputDto([
                    'transcriptionsPerDay' => $current['transcriptionsPerDay'],
                    'improvementsPerDay' => $current['improvementsPerDay'],
                    'regenerationsPerDay' => $current['regenerationsPerDay'],
                    'maxAudioSeconds' => $current['maxAudioSeconds'],
                    'applyToWebBooks' => $current['applyToWebBooks'] ? '1' : '0',
                    'timezone' => $request->request->get('timezone'),
                ]);
            } else {
                $dto = new AiQuotaSettingsInputDto([
                    'transcriptionsPerDay' => $request->request->get('transcriptionsPerDay'),
                    'improvementsPerDay' => $request->request->get('improvementsPerDay'),
                    'regenerationsPerDay' => $request->request->get('regenerationsPerDay'),
                    'maxAudioSeconds' => $request->request->get('maxAudioSeconds'),
                    'applyToWebBooks' => $request->request->getBoolean('applyToWebBooks') ? '1' : '0',
                    'timezone' => $request->request->get('timezone'),
                ]);
            }
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

    /**
     * @return array{transcriptionsPerDay: int, improvementsPerDay: int, regenerationsPerDay: int, maxAudioSeconds: int, applyToWebBooks: bool}
     */
    private function currentQuotasFromDb(): array
    {
        $row = $this->emProvider->getEntityManager()->getConnection()->fetchAssociative(
            'SELECT transcriptions_per_day, improvements_per_day, regenerations_per_day, max_audio_seconds, apply_to_web_books
             FROM mv_ai_quota_setting ORDER BY id ASC LIMIT 1'
        );
        if (!is_array($row)) {
            $defaults = new AiQuotaSetting();

            return [
                'transcriptionsPerDay' => $defaults->getTranscriptionsPerDay(),
                'improvementsPerDay' => $defaults->getImprovementsPerDay(),
                'regenerationsPerDay' => $defaults->getRegenerationsPerDay(),
                'maxAudioSeconds' => $defaults->getMaxAudioSeconds(),
                'applyToWebBooks' => $defaults->isApplyToWebBooks(),
            ];
        }

        return [
            'transcriptionsPerDay' => (int) $row['transcriptions_per_day'],
            'improvementsPerDay' => (int) $row['improvements_per_day'],
            'regenerationsPerDay' => (int) $row['regenerations_per_day'],
            'maxAudioSeconds' => (int) $row['max_audio_seconds'],
            'applyToWebBooks' => (bool) $row['apply_to_web_books'],
        ];
    }
}

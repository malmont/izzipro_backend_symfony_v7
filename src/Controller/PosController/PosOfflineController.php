<?php

namespace App\Controller\PosController;

use App\Entity\User;
use App\Services\CaisseService\CashRegisterException;
use App\Services\CaisseService\CashRegisterResolver;
use App\Services\PosAuditService\PosTerminalService;
use App\Services\PosOfflineService\PosOfflinePackService;
use App\Services\PosOfflineService\PosOfflineSyncService;
use App\Entity\PosOfflineSync;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Mode hors ligne du POS (C5 partie 2, 25/10/2026) : pack avec ETag, jeu de cas partagé, synchronisation des ventes, historique */
class PosOfflineController extends AbstractController
{
    public function __construct(
        private readonly PosOfflinePackService $packs,
        private readonly PosOfflineSyncService $sync,
        private readonly PosTerminalService $terminals,
        private readonly CashRegisterResolver $registers,
        private readonly TenantEntityManagerProvider $emProvider,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
    }

    /** ?cashRegisterId= ; If-None-Match → 304 */
    #[Route('/api/pos/offline-pack', name: 'pos_offline_pack', methods: ['GET'])]
    public function pack(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request) {
            $register = is_numeric($request->query->get('cashRegisterId')) ? $this->registers->resolve((int) $request->query->get('cashRegisterId')) : null;
            $pack = $this->packs->build($register);
            $etag = '"' . $pack['etag'] . '"';
            // Un relais peut renvoyer un ETag faible (W/"…") : comparaison sans le préfixe
            if (trim((string) preg_replace('#^W/#', '', (string) $request->headers->get('If-None-Match', ''))) === $etag) {
                return new JsonResponse(null, 304, ['ETag' => $etag]);
            }
            unset($pack['etag']);
            $response = $this->json($pack);
            $response->headers->set('ETag', $etag);
            $response->headers->set('Cache-Control', 'private, no-cache');

            return $response;
        });
    }

    /** Règle de calcul et jeu de cas partagé (config/pos/offline-cases.json) */
    #[Route('/api/pos/offline-cases', name: 'pos_offline_cases', methods: ['GET'])]
    public function cases(): JsonResponse
    {
        return $this->json(PosOfflinePackService::cases($this->projectDir));
    }

    /** Lot de ventes hors ligne ; X-Pos-Terminal obligatoire (clé d'idempotence liée au terminal) */
    #[Route('/api/pos/offline-sales', name: 'pos_offline_sales', methods: ['POST'])]
    public function sales(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request) {
            $terminal = $this->terminals->current() ?? throw new CashRegisterException('TERMINAL_REQUIRED', 'La synchronisation hors ligne exige un terminal identifié (X-Pos-Terminal)', 403);
            $body = json_decode($request->getContent(), true);
            if (!is_array($body)) {
                throw new CashRegisterException('INVALID_BODY', 'Corps JSON invalide', 400);
            }

            return $this->json($this->sync->process($this->user(), (int) $terminal['id'], $body));
        });
    }

    /** ?terminalId=&limit= : lots synchronisés (responsable, administrateur) */
    #[Route('/api/pos/offline-sales', name: 'pos_offline_sales_history', methods: ['GET'])]
    public function history(Request $request): JsonResponse
    {
        if (!$this->isGranted('ROLE_POS_MANAGER') && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException('Réservé au responsable de caisse ou à l\'administrateur');
        }
        $criteria = is_numeric($request->query->get('terminalId')) ? ['terminalId' => (int) $request->query->get('terminalId')] : [];
        $limit = min(200, max(1, (int) $request->query->get('limit', 50)));
        $rows = $this->emProvider->getEntityManager()->getRepository(PosOfflineSync::class)->findBy($criteria, ['id' => 'DESC'], $limit);

        return $this->json(['syncs' => array_map(fn (PosOfflineSync $s) => ['syncId' => $s->getId(), 'terminalId' => $s->getTerminalId(), 'syncedById' => $s->getSyncedById(), 'count' => $s->getCount(), 'accepted' => $s->getAccepted(),
            'duplicates' => $s->getDuplicates(), 'rejected' => $s->getRejected(), 'driftTotal' => $s->getDriftTotal(), 'chainBroken' => $s->isChainBroken(), 'clockOffsetSeconds' => $s->getClockOffsetSeconds(), 'packVersion' => $s->getPackVersion(), 'createdAt' => $s->getCreatedAt()->format(\DateTimeInterface::ATOM)], $rows)]);
    }

    private function guard(callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (CashRegisterException $e) {
            return $this->json($e->toBody(), $e->status);
        }
    }

    private function user(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new CashRegisterException('UNAUTHENTICATED', 'Utilisateur non connecté', 401);
        }

        return $user;
    }
}

<?php

namespace App\Controller\PosController;

use App\Services\CaisseService\CashRegisterException;
use App\UseCase\PosAuditUseCase\PosAuditUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Journal d'audit de la caisse (chantier C2, 19/10/2026) : responsable de caisse et administrateur (access_control) */
class PosAuditController extends AbstractController
{
    public function __construct(private readonly PosAuditUseCase $audit)
    {
    }

    /** ?cashRegisterId=&from=&to=&type=SALE,REFUND&page=1&pageSize=50 (200 au plus) ; plus récent d'abord */
    #[Route('/api/pos/audit', name: 'pos_audit_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        try {
            $q = $request->query;

            return $this->json($this->audit->list($q->get('cashRegisterId'), $q->get('from'), $q->get('to'), $q->get('type'), $q->get('page'), $q->get('pageSize')));
        } catch (CashRegisterException $e) {
            return $this->json($e->toBody(), $e->status);
        }
    }

    #[Route('/api/pos/audit/verify', name: 'pos_audit_verify', methods: ['GET'])]
    public function verify(): JsonResponse
    {
        return $this->json($this->audit->verify());
    }
}

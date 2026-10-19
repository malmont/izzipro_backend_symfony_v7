<?php

namespace App\UseCase\PosAuditUseCase;

use App\Entity\PosAuditLog;
use App\Services\CaisseService\CashRegisterException;
use App\Services\PosAuditService\PosAuditService;
use App\Services\TenantEntityManagerProvider;
use App\UseCase\CaisseUseCase\CashReportsUseCase;

/** Lecture du journal d'audit de la caisse (GET /api/pos/audit) et contrôle du chaînage (GET /api/pos/audit/verify) */
class PosAuditUseCase
{
    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly PosAuditService $audit)
    {
    }

    /** @return array{meta: array{total: int, page: int, pageSize: int}, data: list<array<string, mixed>>} */
    public function list(?string $cashRegisterId, ?string $from, ?string $to, ?string $type, mixed $page, mixed $pageSize): array
    {
        $types = array_values(array_filter(array_map('trim', explode(',', (string) $type))));
        foreach ($types as $t) {
            if (!in_array($t, PosAuditService::TYPES, true)) {
                throw new CashRegisterException('INVALID_TYPE', sprintf('type : %s inconnu (%s)', $t, implode(', ', PosAuditService::TYPES)), 400);
            }
        }
        $page = max(1, (int) ($page ?: 1));
        $pageSize = min(200, max(1, (int) ($pageSize ?: 50)));
        [$rows, $total] = $this->emProvider->getEntityManager()->getRepository(PosAuditLog::class)->search(
            is_numeric($cashRegisterId) ? (int) $cashRegisterId : null,
            CashReportsUseCase::date($from, 'from', false),
            CashReportsUseCase::date($to, 'to', true),
            $types, $page, $pageSize
        );

        return ['meta' => ['total' => $total, 'page' => $page, 'pageSize' => $pageSize], 'data' => array_map([self::class, 'entry'], $rows)];
    }

    public function verify(): array
    {
        return $this->audit->verify();
    }

    /** Entrée telle que rendue par l'API : le contenu haché, plus l'id, l'empreinte et l'empreinte précédente */
    public static function entry(PosAuditLog $log): array
    {
        return ['id' => $log->getId()] + $log->getData() + ['prevHash' => $log->getPrevHash(), 'hash' => $log->getHash()];
    }
}

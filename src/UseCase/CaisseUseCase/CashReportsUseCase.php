<?php

namespace App\UseCase\CaisseUseCase;

use App\Dto\CashRegisterReportOutputDto;
use App\Entity\CashRegisterReport;
use App\Services\CaisseService\CashRegisterException;
use App\Services\CaisseService\CashRegisterResolver;
use App\Services\CaisseService\CashSessionReportBuilder;
use App\Services\TenantEntityManagerProvider;

/** Rapports de caisse (19/10/2026) : X de la session ouverte d'un poste, liste et détail des Z (figés) */
class CashReportsUseCase
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly CashRegisterResolver $registers,
        private readonly CashSessionReportBuilder $builder,
        private readonly \App\Services\PosAuditService\PosAuditService $audit
    ) {
    }

    /** Rapport X : session ouverte du poste (409 CASH_REGISTER_CLOSED sinon), calculé à la lecture, ne modifie rien */
    public function x(?int $cashRegisterId, string $locale, ?string $purpose = null): array
    {
        $session = $this->registers->requireOpenSession($cashRegisterId, 409);
        $report = $this->builder->build($session, $locale);
        // Journal (C2) : consultation volontaire X_REPORT_VIEWED ; préparation de la fermeture (purpose=closing) CLOSING_PREVIEW
        $Audit = \App\Services\PosAuditService\PosAuditService::class;
        $this->audit->record($purpose === 'closing' ? $Audit::CLOSING_PREVIEW : $Audit::X_REPORT_VIEWED,
            ['expectedCash' => $report['expectedCash'], 'salesTotal' => $report['sales']['total'], 'salesCount' => $report['sales']['count']], $session, null, 'session', $session->getId());

        return $report;
    }

    /** @return list<array<string, mixed>> */
    public function listZ(?int $cashRegisterId, ?string $from, ?string $to): array
    {
        $register = $this->registers->resolve($cashRegisterId);
        $repository = $this->emProvider->getEntityManager()->getRepository(CashRegisterReport::class);

        return array_map([CashRegisterReportOutputDto::class, 'summary'], $repository->findForRegister($register, self::date($from, 'from', false), self::date($to, 'to', true)));
    }

    public function z(int $id): array
    {
        $report = $this->emProvider->getEntityManager()->getRepository(CashRegisterReport::class)->find($id)
            ?? throw new CashRegisterException('REPORT_NOT_FOUND', 'Rapport Z introuvable', 404);

        return CashRegisterReportOutputDto::detail($report);
    }

    /** AAAA-MM-JJ (journée entière) ou date-heure ISO 8601 ; invalide → 400 INVALID_DATE */
    public static function date(?string $value, string $name, bool $endOfDay): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

            return $date ? ($endOfDay ? $date->setTime(23, 59, 59) : $date) : throw new CashRegisterException('INVALID_DATE', "$name : date AAAA-MM-JJ attendue", 400);
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            throw new CashRegisterException('INVALID_DATE', "$name : date AAAA-MM-JJ ou ISO 8601 attendue", 400);
        }
    }
}

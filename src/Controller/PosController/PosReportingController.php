<?php

namespace App\Controller\PosController;

use App\Entity\User;
use App\Services\CaisseService\CashRegisterException;
use App\UseCase\PosReportingUseCase\PosReportingUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Statistiques par caissier et exports comptables (lot F14, 29/10/2026), routes propres au POS, lecture seule ; les 11 routes
 * de statistiques existantes sont inchangées. Caissier : ses propres chiffres ; exports : responsable. Contrat : docs/caisse.md.
 */
class PosReportingController extends AbstractController
{
    public function __construct(private readonly PosReportingUseCase $reporting)
    {
    }

    /** ?from=AAAA-MM-JJ&to=&cashRegisterId=&cashierId= (journées du fuseau du magasin ; défaut : aujourd'hui) */
    #[Route('/api/pos/statistics/cashiers', name: 'pos_statistics_cashiers', methods: ['GET'])]
    public function cashiers(Request $request): JsonResponse
    {
        return $this->guard(fn () => $this->json($this->reporting->cashiers($this->user(), $request->query->all())));
    }

    /** Aperçu JSON de l'export comptable (jours, écritures, équilibre, rapports anciens signalés) */
    #[Route('/api/pos/exports/accounting', name: 'pos_export_accounting', methods: ['GET'])]
    public function accounting(Request $request): JsonResponse
    {
        return $this->guard(fn () => $this->json($this->reporting->accounting($this->user(), $request->query->all(), 'json')));
    }

    /** Synthèse CSV par jour (UTF-8 BOM, « ; », virgule décimale) */
    #[Route('/api/pos/exports/accounting/summary.csv', name: 'pos_export_accounting_summary', methods: ['GET'])]
    public function summary(Request $request): Response
    {
        return $this->guard(function () use ($request) {
            $export = $this->reporting->accounting($this->user(), $request->query->all(), 'summary.csv');

            return $this->csv($this->reporting->summaryCsv($export), sprintf('synthese-caisse_%s_%s.csv', $export['period']['from'], $export['period']['to']));
        });
    }

    /** Écritures CSV : ?format=generic (défaut, « ; ») | qbo (QuickBooks Online : « , », point décimal, JJ/MM/AAAA) */
    #[Route('/api/pos/exports/accounting/entries.csv', name: 'pos_export_accounting_entries', methods: ['GET'])]
    public function entries(Request $request): Response
    {
        return $this->guard(function () use ($request) {
            $format = $request->query->get('format', 'generic') === 'qbo' ? 'qbo' : 'generic';
            $export = $this->reporting->accounting($this->user(), $request->query->all(), 'entries.' . $format);

            return $this->csv($this->reporting->entriesCsv($export, $format), sprintf('ecritures-caisse_%s_%s_%s.csv', $format, $export['period']['from'], $export['period']['to']));
        });
    }

    private function csv(string $content, string $filename): Response
    {
        return new Response($content, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="' . $filename . '"', 'Cache-Control' => 'no-store']);
    }

    private function guard(callable $action): Response
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

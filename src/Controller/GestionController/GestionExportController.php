<?php

namespace App\Controller\GestionController;

use App\UseCase\GestionUseCase\ExportPurchasesUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Export comptable des achats de l'application de gestion (lot G5, 10/11/2026) : `ROLE_ADMIN` (`access_control` `/api/gestion`).
 * `?from=AAAA-MM-JJ&to=AAAA-MM-JJ` (défaut : mois courant du magasin), `?collectionId=` facultatif ; 422 `VALIDATION_FAILED`
 * (`from` / `to` : INVALID_DATE, BEFORE_FROM). Téléchargement CSV (« ; », BOM, virgule décimale), nom `achats_<from>_<to>.csv`.
 */
class GestionExportController extends AbstractController
{
    public function __construct(private readonly ExportPurchasesUseCase $export)
    {
    }

    #[Route('/api/gestion/exports/achats.csv', name: 'gestion_export_achats', methods: ['GET'])]
    public function purchases(Request $request): Response
    {
        $collectionId = $request->query->get('collectionId');
        $result = $this->export->execute(
            $request->query->get('from') !== null ? (string) $request->query->get('from') : null,
            $request->query->get('to') !== null ? (string) $request->query->get('to') : null,
            $collectionId !== null && $collectionId !== '' ? (int) $collectionId : null
        );

        return new Response($result['csv'], Response::HTTP_OK, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $result['filename'] . '"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}

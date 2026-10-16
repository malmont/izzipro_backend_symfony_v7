<?php

namespace App\MemoiresVivantes\Controller\Admin;

use App\MemoiresVivantes\UseCase\Admin\ListPaymentsUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Écran « Paiements » de la console d'administration (16/10/2026), lecture seule, ROLE_ADMIN. Contrat :
 * docs/memoires-vivantes.md, « Rédaction payante et livre imprimé », § Registre des paiements.
 */
#[Route('/api/memoires/admin/payments')]
#[IsGranted('ROLE_ADMIN')]
class PaymentsAdminController extends AbstractController
{
    public function __construct(private readonly ListPaymentsUseCase $payments)
    {
    }

    /** ?from&to&kind&status&store&search&page&perPage ; CSV avec ?format=csv ou Accept: text/csv */
    #[Route('', methods: ['GET'])]
    public function list(Request $request): Response
    {
        $query = $request->query->all();
        $csv = ($query['format'] ?? null) === 'csv' || str_contains((string) $request->headers->get('Accept', ''), 'text/csv');
        try {
            if ($csv) {
                return new Response($this->payments->csv($query), 200, [
                    'Content-Type' => 'text/csv; charset=UTF-8',
                    'Content-Disposition' => 'attachment; filename="paiements-memoires-vivantes.csv"',
                ]);
            }

            return $this->json($this->payments->execute($query));
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => 'VALIDATION_FAILED', 'message' => $e->getMessage(), 'errors' => [['path' => explode(' ', $e->getMessage())[0], 'message' => $e->getMessage()]]], 422);
        }
    }
}

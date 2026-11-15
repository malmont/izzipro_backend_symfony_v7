<?php

namespace App\Controller\CommandeController;

use App\Gestion\Services\GestionConflictException;
use App\Services\CommandeService\CommandeLabelService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Étiquettes de codes-barres d'une commande fournisseur reçue (lot CB-2, 15/11/2026, `docs/codes-barres.md` § 7), `ROLE_ADMIN`
 * (`access_control` `/api/commandes`) : la liste, la génération des codes manquants, le PDF de la sélection. Aucune écriture de stock.
 */
class CommandeLabelController extends AbstractController
{
    public function __construct(private readonly CommandeLabelService $labels)
    {
    }

    /** Étiquettes à imprimer : une ligne par variante ayant reçu du stock sur la commande → 200 { commande, format, labels, missing, skipped, totals } */
    #[Route('/api/commandes/{id}/etiquettes', name: 'commande_labels_list', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function list(int $id): JsonResponse
    {
        return $this->json($this->labels->labels($this->labels->commande($id)));
    }

    /** Un code généré pour les variantes reçues qui n'en ont pas ; ne remplace jamais un code ; idempotent → 200 { generated, …la liste à jour } */
    #[Route('/api/commandes/{id}/etiquettes/codes', name: 'commande_labels_generate', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function generate(int $id, Request $request): JsonResponse
    {
        return $this->json($this->labels->generate($this->labels->commande($id), $this->body($request)));
    }

    /** Le PDF de la sélection (planches A4 d'étiquettes 50 × 30 mm) → 200 application/pdf, en-tête X-Labels-Count */
    #[Route('/api/commandes/{id}/etiquettes/pdf', name: 'commande_labels_pdf', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function pdf(int $id, Request $request): Response
    {
        $pdf = $this->labels->pdf($this->labels->commande($id), $this->body($request), $request->query->get('locale') === 'en' ? 'en' : 'fr');

        return new Response($pdf['content'], Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename="etiquettes-commande-%d.pdf"', $id),
            'X-Labels-Count' => (string) $pdf['count'],
        ]);
    }

    /** Corps JSON facultatif (vide = {}) */
    private function body(Request $request): array
    {
        $content = trim((string) $request->getContent());
        $data = $content === '' ? [] : json_decode($content, true);
        if (!is_array($data)) {
            throw new GestionConflictException('INVALID_JSON', 'Corps JSON invalide', 400);
        }

        return $data;
    }
}

<?php

namespace App\Gestion\Controller;

use App\Entity\Commande;
use App\Gestion\Services\Document\GestionDocumentService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Factures confirmées d'une commande fournisseur (lot IA-FACT-2-A, 13/11/2026, `docs/conversation-gestion.md` § 7) : la facture d'origine
 * et celles ajoutées ensuite, avec ce que chacune a apporté ligne par ligne. Lecture seule ; `ROLE_ADMIN` (`access_control`
 * `/api/commandes`).
 */
class CommandeDocumentsController extends AbstractController
{
    public function __construct(private readonly GestionDocumentService $documents)
    {
    }

    /** → [{ id, type, invoiceNumber, invoiceDate, confirmedAt, added, lines: [{ lineId, productId, variantId, quantity, unitCost }], undo: { available, until } }] */
    #[Route('/api/commandes/{id}/documents', name: 'commande_documents', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function list(Commande $commande): JsonResponse
    {
        return $this->json($this->documents->forCommande((int) $commande->getId()));
    }
}

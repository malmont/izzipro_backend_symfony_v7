<?php

namespace App\Controller\CommandeController;

use App\Services\CommandeService\CommandeProductException;
use App\Services\MediaUrlResolver;
use App\UseCase\CommandeUseCase\CommandeProductAttachmentUseCase;
use App\UseCase\CommandeDashboardUseCase\DashboardCommandeUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Rattachement de produits existants à une commande fournisseur (09/11/2026, application de gestion ; routes additives, les routes de
 * commandes existantes ne changent pas). Contrat : docs/architecture.md « Rattachement de produits existants à une commande fournisseur ».
 */
#[IsGranted('ROLE_ADMIN')]
class CommandeProductAttachmentController extends AbstractController
{
    public function __construct(private readonly CommandeProductAttachmentUseCase $useCase, private readonly MediaUrlResolver $media, private readonly ?DashboardCommandeUseCase $dashboard = null)
    {
    }

    /** Produits sans commande : ?q= (nom, code, code-barres), ?page=1, ?limit=30 (≤ 100), ?locale=fr */
    #[Route('/api/commandes/products/disponibles', name: 'commande_products_available', methods: ['GET'])]
    public function available(Request $request): JsonResponse
    {
        return $this->guard(fn () => $this->json($this->useCase->available($request->query->get('q'), $request->query->get('page'), $request->query->get('limit'), $this->media->getProductsBaseUrl($request->getSchemeAndHttpHost()), (string) $request->query->get('locale', 'fr'), $request->query->get('scope'))));
    }

    #[Route('/api/commandes/{id}/products/rattacher', name: 'commande_products_attach', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function attach(int $id, Request $request): JsonResponse
    {
        return $this->guard(function () use ($id, $request) {
            $result = $this->useCase->attach($id, json_decode((string) $request->getContent(), true), $this->getUser()?->getUserIdentifier());
            $commande = $this->useCase->commandeEntity($id);
            $metrics = $this->dashboard?->execute($commande)->toArray();

            return $this->json($result + ['commande' => ['id' => $id, 'usedBudget' => $metrics['BudgetGeneral']['usedBudget'] ?? null, 'remainingBudget' => $metrics['BudgetGeneral']['remainingBudget'] ?? null]]);
        });
    }

    #[Route('/api/commandes/{id}/products/{productId}', name: 'commande_products_detach', methods: ['DELETE'], requirements: ['id' => '\d+', 'productId' => '\d+'])]
    public function detach(int $id, int $productId): JsonResponse
    {
        return $this->guard(function () use ($id, $productId) {
            $this->useCase->detach($id, $productId, $this->getUser()?->getUserIdentifier());

            return new JsonResponse(null, 204);
        });
    }

    private function guard(callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (CommandeProductException $e) {
            return $this->json($e->toBody(), $e->status);
        }
    }
}

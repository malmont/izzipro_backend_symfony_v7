<?php

namespace App\Controller\Admin;

use App\Services\ProductAiService\ProductAiBulkService;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * « IA en lot » (back-office, 07/11/2026) : pour les fiches, les traductions et les images mannequin, le nombre de produits concernés, les
 * crédits nécessaires, restants et manquants, puis un bouton pour tout lancer en arrière-plan ; l'avancement s'affiche en direct.
 */
#[IsGranted('ROLE_ADMIN')]
class ProductAiBulkAdminController extends AbstractController
{
    private const CSRF = 'admin_product_ai_bulk';
    private const SESSION = 'admin_product_ai_bulk_job';

    public function __construct(private readonly ProductAiBulkService $bulk, private readonly AdminUrlGenerator $urls)
    {
    }

    #[Route('/admin/produits/ia-en-lot', name: 'admin_product_ai_bulk', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $charge = !$this->isGranted('ROLE_SUPER_ADMIN');
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
                $this->addFlash('danger', 'Jeton de sécurité invalide : rechargez la page.');

                return $this->redirect($this->selfUrl());
            }
            $kind = (string) $request->request->get('kind', '');
            if (!in_array($kind, ProductAiBulkService::KINDS, true)) {
                $this->addFlash('danger', 'Lot inconnu.');

                return $this->redirect($this->selfUrl());
            }
            $result = $this->bulk->start($kind, $request->request->get('overwrite') === '1', $request->request->get('partial') === '1', null, $this->getUser()?->getUserIdentifier(), $charge);
            if ($result['status'] === 202) {
                $request->getSession()->set(self::SESSION, $result['body']['jobId']);
                $this->addFlash('success', sprintf('%s : %d produit(s) en cours de traitement en arrière-plan%s. Vous pouvez quitter la page, l\'avancement reste visible ici.', ProductAiBulkService::LABELS[$kind], $result['body']['products'], $result['body']['partial'] ? ' (une partie seulement, selon vos crédits)' : ''));
            } else {
                $this->addFlash($result['status'] === 402 ? 'warning' : 'danger', $result['body']['message']);
            }

            return $this->redirect($this->selfUrl());
        }
        $plans = [];
        foreach (ProductAiBulkService::KINDS as $kind) {
            $plans[$kind] = $this->bulk->plan($kind, false, $charge);
        }
        $jobId = $request->getSession()->get(self::SESSION);
        $job = is_string($jobId) ? $this->bulk->status($jobId) : null;

        return $this->render('admin/produits/ia_en_lot.html.twig', ['plans' => $plans, 'job' => $job, 'csrfToken' => self::CSRF, 'charge' => $charge, 'statusUrl' => $job ? $this->generateUrl('admin_product_ai_bulk_status', ['jobId' => $job['jobId']]) : null]);
    }

    /** Avancement d'un lot (lu par la page toutes les 4 secondes) */
    #[Route('/admin/produits/ia-en-lot/etat/{jobId}', name: 'admin_product_ai_bulk_status', methods: ['GET'], requirements: ['jobId' => '[0-9a-f-]{36}'])]
    public function status(string $jobId): JsonResponse
    {
        $status = $this->bulk->status($jobId);

        return $status === null ? $this->json(['error' => 'JOB_NOT_FOUND'], 404) : $this->json($status);
    }

    private function selfUrl(): string
    {
        try {
            return $this->urls->unsetAll()->setRoute('admin_product_ai_bulk')->generateUrl();
        } catch (\Throwable) {
            return $this->generateUrl('admin_product_ai_bulk');
        }
    }
}

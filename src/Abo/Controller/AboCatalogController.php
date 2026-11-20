<?php

namespace App\Abo\Controller;

use App\Abo\Services\AboException;
use App\Abo\UseCase\AboCatalogUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Création et configuration des formules et des prestations par la GESTION WEB (lot ABO-CONF, 19/11/2026, décision du propriétaire) ; les
 * mobiles ne créent rien. `ROLE_ADMIN`, module Boutique ; mêmes contrôles qu'EasyAdmin (service commun). Formules branchées de la plateforme :
 * EasyAdmin seulement (403 PLAN_PLATFORM_ONLY). Un refus rend `{ error: CODE, code, message, field? }`. Langue : `?locale=`.
 */
#[IsGranted('ROLE_ADMIN')]
class AboCatalogController extends AbstractController
{
    public function __construct(private readonly AboCatalogUseCase $useCase)
    {
    }

    /** Ce que sera la formule, SANS rien écrire : `{ plan, summary, priceChange, warnings }` (`?planId=` pour une formule existante) */
    #[Route('/api/site/abonnements/formules/apercu', name: 'site_abo_plan_preview', methods: ['POST'])]
    public function preview(Request $request): JsonResponse
    {
        $id = $request->query->get('planId');

        return $this->guard(fn () => $this->useCase->preview(is_numeric($id) ? (int) $id : null, self::body($request), self::locale($request)));
    }

    #[Route('/api/site/abonnements/formules', name: 'site_abo_plan_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        return $this->guard(fn () => $this->useCase->create(self::body($request), $this->by(), self::locale($request)), 201);
    }

    /** La fiche d'une formule : tête, prestations, historique des prix, « ce que verra l'abonné » */
    #[Route('/api/site/abonnements/formules/{id}', name: 'site_abo_plan_get', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function plan(Request $request, int $id): JsonResponse
    {
        return $this->guard(fn () => $this->useCase->plan($id, self::locale($request)));
    }

    #[Route('/api/site/abonnements/formules/{id}', name: 'site_abo_plan_update', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    public function update(Request $request, int $id): JsonResponse
    {
        return $this->guard(fn () => $this->useCase->update($id, self::body($request), $this->by(), self::locale($request)));
    }

    #[Route('/api/site/abonnements/formules/{id}/archiver', name: 'site_abo_plan_archive', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function archive(Request $request, int $id): JsonResponse
    {
        return $this->guard(fn () => $this->useCase->archive($id, true, self::locale($request)));
    }

    #[Route('/api/site/abonnements/formules/{id}/reactiver', name: 'site_abo_plan_reactivate', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function reactivate(Request $request, int $id): JsonResponse
    {
        return $this->guard(fn () => $this->useCase->archive($id, false, self::locale($request)));
    }

    /** 204 ; 409 PLAN_HAS_SUBSCRIBERS dès qu'elle a eu un abonné (l'archiver) */
    #[Route('/api/site/abonnements/formules/{id}', name: 'site_abo_plan_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function delete(int $id): Response
    {
        try {
            $this->useCase->delete($id);

            return new Response(null, 204);
        } catch (AboException $e) {
            return $this->json($e->toArray(), $e->status);
        }
    }

    /** La liste COMPLÈTE des prestations de la formule `{ benefits: [{ benefitId, quantity, scheduling, … }] }` */
    #[Route('/api/site/abonnements/formules/{id}/prestations', name: 'site_abo_plan_benefits', requirements: ['id' => '\d+'], methods: ['PUT'])]
    public function setBenefits(Request $request, int $id): JsonResponse
    {
        return $this->guard(fn () => $this->useCase->setBenefits($id, self::body($request), self::locale($request)));
    }

    #[Route('/api/site/abonnements/prestations', name: 'site_abo_benefits', methods: ['GET'])]
    public function benefits(Request $request): JsonResponse
    {
        return $this->guard(fn () => $this->useCase->benefits(self::locale($request)));
    }

    #[Route('/api/site/abonnements/prestations', name: 'site_abo_benefit_create', methods: ['POST'])]
    public function createBenefit(Request $request): JsonResponse
    {
        return $this->guard(fn () => $this->useCase->saveBenefit(null, self::body($request), self::locale($request)), 201);
    }

    #[Route('/api/site/abonnements/prestations/{id}', name: 'site_abo_benefit_update', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    public function updateBenefit(Request $request, int $id): JsonResponse
    {
        return $this->guard(fn () => $this->useCase->saveBenefit($id, self::body($request), self::locale($request)));
    }

    /** 204 ; 409 BENEFIT_IN_USE si une formule la livre ou si des droits la citent (la désactiver) */
    #[Route('/api/site/abonnements/prestations/{id}', name: 'site_abo_benefit_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function deleteBenefit(int $id): Response
    {
        try {
            $this->useCase->deleteBenefit($id);

            return new Response(null, 204);
        } catch (AboException $e) {
            return $this->json($e->toArray(), $e->status);
        }
    }

    private function guard(callable $fn, int $status = 200): JsonResponse
    {
        try {
            return $this->json($fn(), $status);
        } catch (AboException $e) {
            return $this->json($e->toArray(), $e->status);
        }
    }

    /** @return array<string, mixed> */
    private static function body(Request $request): array
    {
        $body = json_decode((string) $request->getContent(), true);

        return is_array($body) ? $body : [];
    }

    private function by(): ?string
    {
        $user = $this->getUser();

        return $user instanceof \App\Entity\User ? (trim((string) $user->getFirstname()) ?: $user->getUserIdentifier()) : null;
    }

    private static function locale(Request $request): string
    {
        return $request->query->get('locale') === 'en' ? 'en' : 'fr';
    }
}

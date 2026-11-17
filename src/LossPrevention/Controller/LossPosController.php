<?php

namespace App\LossPrevention\Controller;

use App\LossPrevention\UseCase\LossCounterUseCase;
use App\Services\CaisseService\CashRegisterException;
use App\Services\EmailConfigurationService\EmailLocale;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * La route que la CAISSE appelle avant un paiement (lot L3-b1, 16/11/2026, `docs/prevention-pertes.md` § 16.15 ; `docs/caisse.md`).
 * Droit du caissier (`access_control` `^/api/pos/pertes`), en-tête de la tablette comme toute la caisse. Une LECTURE : rien n'est
 * écrit, la vente n'est jamais bloquée. Sans le module, sans tapis, tapis silencieux : 200 `active: false`. La caisse traite toute
 * autre réponse, et tout retard, comme « pas de contrôle ».
 * Lot L5-a (17/11/2026, § 18.2, « B ») : la réponse dit aussi si la caisse montre l'alerte (`display`, `notice`, `checkId`) ; la suite
 * de l'alerte, au paiement, n'ajoute qu'un aux compteurs du poste et du jour.
 */
class LossPosController extends AbstractController
{
    public function __construct(private readonly LossCounterUseCase $useCase, private readonly ?EmailLocale $siteLocale = null)
    {
    }

    /** Articles suivis du panier non lus sur le tapis de ce poste `{ cashRegisterId?, items }` → 200 { active, reason, cashRegisterId, tracked, read, unread, extra, checkedAt, display, notice, checkId } */
    #[Route('/api/pos/pertes/articles-non-lus', name: 'pos_pertes_articles_non_lus', methods: ['POST'])]
    public function unread(Request $request): JsonResponse
    {
        $content = trim((string) $request->getContent());
        $body = $content === '' ? [] : json_decode($content, true);
        try {
            if (!is_array($body)) {
                throw new CashRegisterException('VALIDATION_FAILED', 'Corps JSON invalide', 422, [['path' => 'body', 'message' => 'JSON attendu']]);
            }

            return $this->json($this->useCase->unread($body, $this->locale($request)));
        } catch (CashRegisterException $e) {
            return $this->json($e->toBody(), $e->status);
        }
    }

    /** Lot L5-a : suite d'une alerte montrée, au paiement `{ checkId, proceeded }` → 204 (rien d'autre, même pour une vérification inconnue) ; 422 corps invalide */
    #[Route('/api/pos/pertes/articles-non-lus/suite', name: 'pos_pertes_articles_non_lus_suite', methods: ['POST'])]
    public function after(Request $request): Response
    {
        $content = trim((string) $request->getContent());
        $body = $content === '' ? [] : json_decode($content, true);
        try {
            if (!is_array($body)) {
                throw new CashRegisterException('VALIDATION_FAILED', 'Corps JSON invalide', 422, [['path' => 'body', 'message' => 'JSON attendu']]);
            }
            $this->useCase->after($body);

            return new Response(null, 204);
        } catch (CashRegisterException $e) {
            return $this->json($e->toBody(), $e->status);
        }
    }

    private function locale(Request $request): string
    {
        $asked = $request->query->get('locale');
        if (is_string($asked) && in_array(strtolower($asked), ['fr', 'en'], true)) {
            return strtolower($asked);
        }

        return $this->siteLocale?->language(null) ?? 'fr';
    }
}

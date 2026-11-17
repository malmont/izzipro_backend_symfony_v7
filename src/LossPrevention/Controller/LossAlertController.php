<?php

namespace App\LossPrevention\Controller;

use App\Entity\User;
use App\LossPrevention\UseCase\LossAlertUseCase;
use App\Services\EmailConfigurationService\EmailLocale;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * La liste en direct des alertes de sortie du jour (lot L5-d, 17/11/2026, `docs/prevention-pertes.md` § 18.11), relue toutes les 30 s par
 * l'écran : l'article, la zone, l'heure ; rien d'une personne. `ROLE_ADMIN` et les gardes de la section, comme toutes les routes du module.
 */
#[Route('/api/gestion/pertes')]
class LossAlertController extends AbstractController
{
    public function __construct(private readonly LossAlertUseCase $useCase, private readonly ?EmailLocale $siteLocale = null)
    {
    }

    /** `?since=` (ISO) : seulement ce qui a changé depuis → { day, exercise, alerts, pushedToday, cap, notice, serverTime } */
    #[Route('/alertes', name: 'gestion_pertes_alertes', methods: ['GET'])]
    public function today(Request $request): JsonResponse
    {
        return $this->json($this->useCase->today($this->since($request), $this->locale($request), false, $this->userId()));
    }

    /** Les alertes d'exercice (ouvertes sans le droit du module, comme les autres routes d'exercice) */
    #[Route('/exercices/alertes', name: 'gestion_pertes_exercices_alertes', methods: ['GET'])]
    public function exerciseToday(Request $request): JsonResponse
    {
        return $this->json($this->useCase->today($this->since($request), $this->locale($request), true, $this->userId()));
    }

    private function since(Request $request): ?string
    {
        $since = $request->query->get('since');

        return is_string($since) ? $since : null;
    }

    private function userId(): int
    {
        $user = $this->getUser();

        return $user instanceof User ? (int) $user->getId() : 0;
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

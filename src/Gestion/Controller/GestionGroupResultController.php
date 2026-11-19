<?php

namespace App\Gestion\Controller;

use App\Services\Group\AllShops\GroupScope;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Lot G-3 (18/11/2026, contrat validé par le chef, `docs/ecrans-et-contextes.md` § 14) : le résultat du GROUPE sur des dates. Une période de
 * gestion est propre à un site (son identifiant, son découpage) : le directeur choisit des dates, et chaque boutique calcule son résultat sur
 * ces dates avec une période NON ENREGISTRÉE (`PeriodResultCalculator::compute`, jamais figé), dans la transaction READ ONLY de « Toutes
 * les boutiques ». Totaux par devise, taux recalculés sur les sommes. Route du groupe seulement : sans l'en-tête `X-Group-Scope: all`,
 * 400 `GROUP_SCOPE_REQUIRED`. `ROLE_ADMIN` (`access_control` `/api/gestion`), module de gestion.
 */
class GestionGroupResultController extends AbstractController
{
    public function __construct(private readonly \App\Services\Group\AllShops\GroupViews $views)
    {
    }

    #[Route('/api/gestion/groupe/resultat', name: 'gestion_groupe_resultat', methods: ['GET'])]
    public function result(Request $request): JsonResponse
    {
        if (!$request->attributes->has(GroupScope::ATTRIBUTE)) {
            return $this->json(['error' => GroupScope::REQUIRED, 'code' => GroupScope::REQUIRED, 'message' => 'Route du groupe : envoyez l\'en-tête X-Group-Scope: all depuis le siège.'], 400);
        }
        [$from, $to] = \App\Services\Group\AllShops\GroupViews::resultRange($request->query->get('from'), $request->query->get('to'));
        $group = $request->attributes->get(GroupScope::ATTRIBUTE);

        return $this->json($this->views->result($group, $from, $to));
    }
}

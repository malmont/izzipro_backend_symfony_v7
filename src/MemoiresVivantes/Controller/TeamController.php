<?php

namespace App\MemoiresVivantes\Controller;

use App\MemoiresVivantes\UseCase\Team\ListTeamUseCase;
use App\Security\MemoiresTeamVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Équipe de Mémoires Vivantes (13/11/2026) : liste légère des biographes, pour le choix du biographe d'un rendez-vous.
 * Administrateur du site ou biographe. Contrat : docs/memoires-vivantes.md, « Équipe ».
 */
#[Route('/api/memoires/team')]
#[IsGranted(MemoiresTeamVoter::ATTRIBUTE)]
final class TeamController extends AbstractController
{
    public function __construct(private readonly ListTeamUseCase $listTeam)
    {
    }

    /** → [{id, fullName, email, teamRoles}] */
    #[Route('', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return $this->json($this->listTeam->execute());
    }
}

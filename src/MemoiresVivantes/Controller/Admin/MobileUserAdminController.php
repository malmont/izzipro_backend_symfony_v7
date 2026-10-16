<?php

namespace App\MemoiresVivantes\Controller\Admin;

use App\MemoiresVivantes\UseCase\Admin\GetMobileUserUseCase;
use App\MemoiresVivantes\UseCase\Admin\ListMobileUsersUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Console d'administration du web : utilisateurs de l'application mobile Living Memory (16/10/2026). ROLE_ADMIN
 * (access_control `^/api/memoires/admin/` et #[IsGranted]). Contrat : docs/memoires-vivantes.md,
 * « Console d'administration : populations et utilisateurs mobile ».
 */
#[Route('/api/memoires/admin/mobile-users')]
#[IsGranted('ROLE_ADMIN')]
class MobileUserAdminController extends AbstractController
{
    public function __construct(private readonly ListMobileUsersUseCase $list, private readonly GetMobileUserUseCase $get)
    {
    }

    /** ?search=&page=1&perPage=20 → {date, timezone, limits, page, perPage, total, users: [...]} */
    #[Route('', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        return $this->json($this->list->execute($request->query->get('search'), $request->query->get('page'), $request->query->get('perPage')));
    }

    /** → {user, books: [{…, chapters}], payments, credits: null, ai: {…, history}} ; 404 hors population mobile */
    #[Route('/{userId}', methods: ['GET'], requirements: ['userId' => '\d+'])]
    public function show(int $userId): JsonResponse
    {
        $detail = $this->get->execute($userId);

        return $detail === null ? $this->json(['error' => 'MOBILE_USER_NOT_FOUND', 'message' => 'Utilisateur inconnu ou hors de la population mobile'], 404) : $this->json($detail);
    }
}

<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Import\OrderArchive;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Page « Commandes d'avant la migration » d'un client, dans l'espace d'administration (lot IMP-3, 16/11/2026,
 * `docs/import-boutique.md` § 10) : les commandes que ce client avait passées dans l'ancienne boutique du commerçant, reprises en
 * archive à part. Lecture seule ; seul l'administrateur du site les voit (le client ne les voit pas dans son compte). Mêmes données
 * que `GET /api/site/imports/archive/orders?userId=`.
 */
#[IsGranted('ROLE_ADMIN')]
class UserArchiveOrdersController extends AbstractController
{
    public function __construct(private readonly OrderArchive $archive, private readonly TenantEntityManagerProvider $emProvider)
    {
    }

    #[Route('/admin/clients/{id}/commandes-avant', name: 'admin_user_archive_orders', methods: ['GET'], requirements: ['id' => '\d+'])] // un identifiant hors bornes est refusé par la garde commune des identifiants de route
    public function index(int $id, Request $request): Response
    {
        $user = $this->emProvider->getEntityManager()->getRepository(User::class)->find($id);
        if (!$user instanceof User) {
            throw $this->createNotFoundException();
        }
        $response = $this->render('admin/users/commandes_avant.html.twig', ['customer' => $user, 'archive' => $this->archive->ordersOf($id, max(1, $request->query->getInt('page', 1)))]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}

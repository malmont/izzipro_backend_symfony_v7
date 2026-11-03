<?php

namespace App\Controller\Admin;

use App\Security\RoleAssignmentPolicy;
use App\Services\SuperAdminService\SuperAdminAccountService;
use App\UseCase\SuperAdminUseCase\ApplySuperAdminAccountUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Écran « Compte super administrateur » (03/11/2026) : état du compte du propriétaire de la plateforme sur chaque base, et formulaire
 * qui le crée ou le met à jour partout d'un seul geste (même courriel, même mot de passe, OTP activé). Réservé à ROLE_SUPER_ADMIN.
 * Le mot de passe saisi n'est ni journalisé ni réaffiché.
 */
class SuperAdminAccountController extends AbstractController
{
    private const CSRF_ID = 'admin_super_admin';

    public function __construct(
        private readonly SuperAdminAccountService $accounts,
        private readonly ApplySuperAdminAccountUseCase $apply,
    ) {
    }

    #[Route('/admin/super-admin', name: 'admin_super_admin', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyAccessUnlessGranted(RoleAssignmentPolicy::SUPER_ADMIN);

        return $this->render('admin/super_admin/index.html.twig', [
            'status' => $this->accounts->status(),
            'results' => $request->getSession()->remove('super_admin_results') ?? [],
            'min_password_length' => SuperAdminAccountService::MIN_PASSWORD_LENGTH,
            'csrf_id' => self::CSRF_ID,
        ]);
    }

    #[Route('/admin/super-admin/appliquer', name: 'admin_super_admin_apply', methods: ['POST'])]
    public function apply(Request $request): Response
    {
        $this->denyAccessUnlessGranted(RoleAssignmentPolicy::SUPER_ADMIN);
        if (!$this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Jeton de sécurité invalide : rien n\'a été modifié.');

            return $this->redirectToRoute('admin_super_admin');
        }
        $outcome = $this->apply->execute(
            (string) $request->request->get('email', ''),
            (string) $request->request->get('password', ''),
            (string) $request->request->get('confirmation', ''),
            (string) $request->request->get('firstname', ''),
            (string) $request->request->get('lastname', ''),
        );
        foreach ($outcome['errors'] as $error) {
            $this->addFlash('danger', $error);
        }
        if ($outcome['results'] !== []) {
            $failed = count(array_filter($outcome['results'], fn ($r) => $r['action'] === 'error'));
            $this->addFlash($failed > 0 ? 'warning' : 'success', sprintf('Compte appliqué sur %d base(s)%s.', count($outcome['results']) - $failed, $failed > 0 ? sprintf(', %d en échec', $failed) : ''));
            $request->getSession()->set('super_admin_results', $outcome['results']);
        }

        return $this->redirectToRoute('admin_super_admin');
    }
}

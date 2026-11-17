<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Security\Entry\AdminEntrySession;
use App\Services\Platform\PlatformAccessJournal;
use App\Services\Storefront\TaxVerificationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * « J'ai vérifié mes taxes » dans EasyAdmin (lot AG-3d, 18/11/2026) : la même vérification que `POST /api/site/taxes/verify`, un
 * administrateur DU site seulement (ni l'opérateur d'une agence, ni une entrée, ni le compte de la plateforme).
 */
#[IsGranted('ROLE_ADMIN')]
class TaxesVerificationAdminController extends AbstractController
{
    public function __construct(private readonly TaxVerificationService $taxes)
    {
    }

    #[Route('/admin/taxes/verifier', name: 'admin_taxes_verifier', methods: ['GET', 'POST'])]
    public function verify(Request $request): Response
    {
        $user = $this->getUser();
        $allowed = $user instanceof User && AdminEntrySession::get($request->getSession()) === null && !PlatformAccessJournal::isPlatformAccount($user->getRoles());
        if ($request->isMethod('POST')) {
            if (!$allowed || !$this->isCsrfTokenValid('admin_taxes_verifier', (string) $request->request->get('_token'))) {
                return new Response('Seul un administrateur du site confirme la vérification de ses taxes.', 403);
            }
            $this->taxes->verify($user);
            $this->addFlash('success', 'Merci : vos taxes sont marquées vérifiées.');

            return $this->redirectToRoute('admin_taxes_verifier');
        }

        return $this->render('admin/taxes_verification.html.twig', ['state' => $this->taxes->state(), 'allowed' => $allowed]);
    }
}

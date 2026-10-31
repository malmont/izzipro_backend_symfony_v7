<?php

namespace App\Controller\Admin;

use App\Services\TenantConnectionManager;
use App\Services\TenantConnectionProvider;
use App\Services\TenantShortCode;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Page « Code court de la boutique » (31/10/2026) : le marchand voit son code (lecture seule) ; le propriétaire de la
 * plateforme (ROLE_SUPER_ADMIN) peut le régénérer, après confirmation : l'ancien code ne marche plus aussitôt.
 */
#[IsGranted('ROLE_ADMIN')]
class ShortCodeAdminController extends AbstractController
{
    public function __construct(private readonly TenantConnectionManager $manager, private readonly TenantConnectionProvider $tenant, private readonly LoggerInterface $logger)
    {
    }

    #[Route('/admin/short-code', name: 'admin_short_code', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $site = (string) ($this->tenant->getTenantCode() ?? '');
        $done = null;
        $error = null;
        if ($request->isMethod('POST')) {
            $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');
            if (!$this->isCsrfTokenValid('admin_short_code', (string) $request->request->get('_token'))) {
                $error = 'Jeton de sécurité invalide : rechargez la page.';
            } else {
                try {
                    $old = $this->manager->shortCodeOf($site);
                    $new = $this->manager->assignShortCode($site, true);
                    $this->logger->warning(sprintf('Code court du site "%s" régénéré par %s : %s → %s', $site, $this->getUser()?->getUserIdentifier() ?? 'admin', $old ?? '-', $new));
                    $this->addFlash('success', sprintf('Nouveau code court : %s. L\'ancien code %s ne fonctionne plus.', TenantShortCode::format($new), TenantShortCode::format($old) ?? '(aucun)'));

                    return $this->redirectToRoute('admin_short_code');
                } catch (\Throwable $e) {
                    $error = 'Régénération impossible : ' . $e->getMessage();
                }
            }
        }

        return $this->render('admin/short_code/index.html.twig', [
            'site' => $site,
            'code' => TenantShortCode::format($this->manager->shortCodeOf($site)),
            'canRegenerate' => $this->isGranted('ROLE_SUPER_ADMIN'),
            'error' => $error,
            'done' => $done,
        ]);
    }
}

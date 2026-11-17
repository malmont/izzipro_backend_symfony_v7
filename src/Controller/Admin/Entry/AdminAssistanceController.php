<?php

namespace App\Controller\Admin\Entry;

use App\Entity\User;
use App\Security\Entry\AdminEntrySession;
use App\Services\Agency\Assistance\AssistanceException;
use App\Services\Agency\Assistance\AssistanceSections;
use App\Services\Agency\Assistance\AssistanceService;
use App\Services\Platform\PlatformAccessJournal;
use App\Services\TenantConnectionProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * L'ASSISTANCE DE L'AGENCE DANS EASYADMIN, côté COMMERÇANT (lots AG-ASSIST et AG-3c, 17/11/2026, `docs/ecrans-et-contextes.md` § 8 et
 * § 9). Même service et mêmes règles que `/api/site/assistance` : un administrateur DU site (jamais un opérateur d'agence ni une entrée)
 * demande l'assistance pour une section, en consultation ou en modification, la suit et la ferme ; le compte de la plateforme lit sans
 * demander ni fermer. Depuis EasyAdmin, toutes les sections se demandent.
 */
#[IsGranted('ROLE_ADMIN')]
class AdminAssistanceController extends AbstractController
{
    public function __construct(private readonly AssistanceService $assistance, private readonly TenantConnectionProvider $tenants)
    {
    }

    #[Route('/admin/assistance', name: 'admin_assistance', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if ($this->merchant($request, false) === null) {
            return $this->redirect('/admin');
        }

        return $this->page($request, null);
    }

    #[Route('/admin/assistance/demander', name: 'admin_assistance_demander', methods: ['POST'])]
    public function ask(Request $request): Response
    {
        $merchant = $this->merchant($request, true);
        if ($merchant === null || !$this->isCsrfTokenValid('admin_assistance', (string) $request->request->get('_token'))) {
            return $this->redirect('/admin');
        }
        try {
            $this->assistance->request($this->tenant(), $request->request->get('section'), $request->request->get('mode'), $merchant, true);
            $this->addFlash('success', 'Demande envoyée à votre agence : l\'accès d\'une heure démarre à sa première visite (la demande expire sans visite sous 24 h).');
        } catch (AssistanceException $e) {
            return $this->page($request, $e->getMessage());
        }

        return $this->redirectToRoute('admin_assistance');
    }

    #[Route('/admin/assistance/{id}/fermer', name: 'admin_assistance_fermer', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function close(int $id, Request $request): Response
    {
        $merchant = $this->merchant($request, true);
        if ($merchant === null || !$this->isCsrfTokenValid('admin_assistance', (string) $request->request->get('_token'))) {
            return $this->redirect('/admin');
        }
        try {
            $this->assistance->close($this->tenant(), $id, $merchant);
            $this->addFlash('success', 'Assistance fermée.');
        } catch (AssistanceException $e) {
            return $this->page($request, $e->getMessage());
        }

        return $this->redirectToRoute('admin_assistance');
    }

    private function page(Request $request, ?string $error): Response
    {
        $sections = [];
        foreach (AssistanceSections::SECTIONS as $key => $spec) {
            $sections[] = ['key' => $key, 'label' => AssistanceSections::label($key, 'fr'), 'writable' => (bool) $spec['writable']];
        }

        return $this->render('admin/entree/assistance.html.twig', ['grants' => $this->assistance->list($this->tenant()), 'sections' => $sections, 'error' => $error,
            'canAsk' => $this->merchant($request, true) !== null], new Response('', $error === null ? 200 : 422));
    }

    /** Un administrateur DU site : ni opérateur d'agence, ni session d'une entrée ; pour demander ou fermer, pas le compte de la plateforme */
    private function merchant(Request $request, bool $write): ?User
    {
        $user = $this->getUser();
        if (!$user instanceof User || AdminEntrySession::get($request->getSession()) !== null || ($write && PlatformAccessJournal::isPlatformAccount($user->getRoles()))) {
            return null;
        }

        return $user;
    }

    private function tenant(): string
    {
        return (string) $this->tenants->getTenantCode();
    }
}

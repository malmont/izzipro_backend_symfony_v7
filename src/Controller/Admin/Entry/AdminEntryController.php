<?php

namespace App\Controller\Admin\Entry;

use App\Entity\User;
use App\Security\AgencyRoles;
use App\Security\Entry\AdminEntrySession;
use App\Security\Entry\AdminPassAuthenticator;
use App\Security\OtpSession;
use App\Services\Entry\AdminPassService;
use App\Services\Entry\EntryException;
use App\Services\Entry\EntryService;
use App\Services\Platform\PlatformAccessJournal;
use App\Services\Screens\ContextService;
use App\Services\TenantConnectionProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * LE SÉLECTEUR DE SITES D'EASYADMIN ET L'ENTRÉE PAR LAISSEZ-PASSER (lot AG-3c, 17/11/2026, `docs/ecrans-et-contextes.md` § 9).
 * Même liste que la gestion web (`/api/me/contexts`) : le super administrateur voit tous les sites, l'agence ses sites gérés, un site
 * seul n'a pas de sélecteur. « Entrer » ouvre l'entrée dans la base maître (tracée des deux côtés) et remet un laissez-passer d'une
 * minute, porté par un formulaire POST auto-soumis vers `/admin/entree` du site visé : jamais dans une adresse.
 */
#[IsGranted('ROLE_ADMIN')]
class AdminEntryController extends AbstractController
{
    public function __construct(
        private readonly ContextService $contexts,
        private readonly EntryService $entries,
        private readonly AdminPassService $passes,
        private readonly TenantConnectionProvider $tenants,
    ) {
    }

    /** Le sélecteur : sites de l'agence, ou tous les sites pour le super administrateur */
    #[Route('/admin/contextes', name: 'admin_contextes', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $user = $this->entrant($request);
        if ($user === null) {
            return $this->redirect('/admin');
        }
        $contexts = $this->contexts->contexts($user->getRoles());

        return $this->render('admin/entree/contextes.html.twig', [
            'mode' => $contexts['mode'],
            'sites' => array_values(array_filter($contexts['sites'], static fn (array $site): bool => !($site['current'] ?? false))),
            'otpFresh' => $this->otpAge($request, $user) !== null,
        ]);
    }

    /** « Entrer » : l'entrée est ouverte, le laissez-passer part par un formulaire POST auto-soumis */
    #[Route('/admin/contextes/entrer', name: 'admin_contextes_entrer', methods: ['POST'])]
    public function enter(Request $request): Response
    {
        $user = $this->entrant($request);
        if ($user === null || !$this->isCsrfTokenValid('admin_contextes_entrer', (string) $request->request->get('_token'))) {
            return $this->refusal('forbidden');
        }
        $age = $this->otpAge($request, $user);
        if ($age === null) {
            return $this->refusal('otp');
        }
        $home = (string) ($this->tenants->getTenantCode() ?? '');
        try {
            $opened = $this->entries->enterFromAdmin($user, $home, (string) $request->request->get('site'));
        } catch (EntryException $e) {
            return $this->refusal((string) ($e->reason ?? $e->errorCode));
        }
        $pass = $this->passes->issue(['entry' => $opened['entry'], 'mode' => $opened['mode'], 'home' => $home, 'target' => $opened['site'], 'otpAt' => time() - $age]
            + ($opened['account'] !== null ? ['account' => $opened['account'], 'email' => (string) $opened['email']] : []));

        $response = $this->render('admin/entree/passage.html.twig', ['action' => AdminPassAuthenticator::PATH . '?tenant=' . rawurlencode($opened['site']), 'pass' => $pass,
            'site' => $opened['site'], 'operator' => $opened['operator']]);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    /** Sans laissez-passer accepté (l'authentificateur répond seul quand il en reçoit un) : refusé */
    #[Route('/admin/entree', name: 'admin_entree', methods: ['POST'])]
    public function pass(): Response
    {
        return $this->refusal('pass_malformed');
    }

    /** « Quitter » l'entrée : fermée au journal, session vidée, retour à la connexion du site d'attache */
    #[Route('/admin/entree/quitter', name: 'admin_entree_quitter', methods: ['POST'])]
    public function leave(Request $request): Response
    {
        $session = $request->getSession();
        $entry = AdminEntrySession::get($session);
        if ($entry === null || !$this->isCsrfTokenValid('admin_entree_quitter', (string) $request->request->get('_token'))) {
            return $this->redirect('/admin');
        }
        $this->entries->endEntry($entry['id'], 'left');
        $session->invalidate();

        return new RedirectResponse('/?tenant=' . rawurlencode($entry['home']));
    }

    /** Le compte qui peut ouvrir une entrée : un compte du site (jamais un opérateur), agence ou plateforme, hors d'une entrée en cours */
    private function entrant(Request $request): ?User
    {
        $user = $this->getUser();
        if (!$user instanceof User || AdminEntrySession::get($request->getSession()) !== null) {
            return null;
        }

        return AgencyRoles::has($user->getRoles()) || PlatformAccessJournal::isPlatformAccount($user->getRoles()) ? $user : null;
    }

    /** L'âge (secondes) de la validation du code de CE compte sur CE site ; null : plus de 12 heures, ou inconnue */
    private function otpAge(Request $request, User $user): ?int
    {
        $age = OtpSession::validatedSince($request->getSession(), $user, $this->tenants->getTenantCode());

        return $age !== null && $age <= AdminPassAuthenticator::OTP_MAX_AGE ? $age : null;
    }

    private function refusal(string $reason): Response
    {
        return $this->render('admin/entree/refus.html.twig', ['reason' => $reason], new Response('', 403));
    }
}

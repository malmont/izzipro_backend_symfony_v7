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
        // lot AG-3d : pour l'agence, le sélecteur est « Tous mes clients »
        private readonly ?\App\Services\Agency\AgencyClientsService $clients = null,
        private readonly ?\App\Services\Agency\AgencyRepository $agencies = null,
        // lot RETOUR : la session reprend le compte d'attache
        private readonly ?\Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface $tokens = null,
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
        $rows = [];
        $agency = $contexts['mode'] === ContextService::MODE_AGENCY ? $this->agencies?->findByHome((string) ($this->tenants->getTenantCode() ?? '')) : null;
        if ($agency !== null && $this->clients !== null) {
            foreach ($this->clients->rows($agency) as $row) {
                $rows[$row['code']] = $row;
            }
        }

        return $this->render('admin/entree/contextes.html.twig', [
            'clients' => $rows,
            'canEnter' => $agency === null || $agency['status'] !== 'suspended',
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
        // lot RETOUR : de quoi revenir au site d'attache sans nouvelle connexion, gardé dans la session seulement
        AdminEntrySession::keepReturn($request->getSession(), ['home' => $home, 'account' => (int) $user->getId(), 'email' => (string) $user->getEmail(), 'otpAt' => time() - $age,
            'password' => hash('sha256', (string) $user->getPassword()), 'entry' => (int) $opened['entry']]);
        $pass = $this->passes->issue(['entry' => $opened['entry'], 'mode' => $opened['mode'], 'home' => $home, 'target' => $opened['site'], 'otpAt' => time() - $age]
            + ($opened['account'] !== null ? ['account' => $opened['account'], 'email' => (string) $opened['email']] : []), $request->getSession()->getId());

        $response = $this->render('admin/entree/passage.html.twig', ['action' => AdminPassAuthenticator::PATH . '?tenant=' . rawurlencode($opened['site']), 'pass' => $pass,
            'site' => $opened['site'], 'operator' => $opened['operator']]);
        $response->headers->set('Cache-Control', 'no-store');
        // la session ouverte ferait réécrire l'en-tête par Symfony (« private, max-age=0 ») : le laissez-passer ne se garde nulle part
        $response->headers->set(\Symfony\Component\HttpKernel\EventListener\AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    /** Sans laissez-passer accepté (l'authentificateur répond seul quand il en reçoit un) : refusé */
    #[Route('/admin/entree', name: 'admin_entree', methods: ['POST'])]
    public function pass(): Response
    {
        return $this->refusal('pass_malformed');
    }

    /**
     * « Revenir à <site d'attache> » (lot RETOUR, 18/11/2026, règles du chef) : l'entrée se ferme ; le compte d'attache est RELU sur son site
     * (même numéro, même adresse, mot de passe inchangé depuis l'ouverture de l'entrée, encore autorisé à entrer, contrôles du compte) et la
     * session le reprend sans nouvelle connexion, sous un nouvel identifiant, avec l'heure d'origine de son code (jamais rajeunie). Une ligne
     * aux deux journaux. Sinon : session vidée, retour à la connexion du site d'attache. POST et jeton CSRF seulement.
     */
    #[Route('/admin/entree/quitter', name: 'admin_entree_quitter', methods: ['POST'])]
    public function leave(Request $request): Response
    {
        $session = $request->getSession();
        $entry = AdminEntrySession::get($session);
        if ($entry === null || !$this->isCsrfTokenValid('admin_entree_quitter', (string) $request->request->get('_token'))) {
            return $this->redirect('/admin');
        }
        $this->entries->endEntry($entry['id'], 'left');
        $back = AdminEntrySession::takeReturn($session);
        $account = $back !== null && $back['home'] === $entry['home'] && $back['entry'] === $entry['id'] && $this->tokens !== null ? $this->entries->returningAccount($back) : null;
        // le libellé des journaux : « Agence X, prénom » ou « Plateforme, prénom », jamais une adresse (relecture de RETOUR)
        $current = $this->getUser();
        $operator = $current instanceof User ? \App\Security\Entry\ExternalOperator::platformLabel((string) $current->getFirstname()) : (string) $current?->getUserIdentifier();
        if ($account === null || !$this->entries->traceReturn($entry['id'], $entry['target'], $entry['home'], $operator)) {
            // relecture de RETOUR : le jeton part AVANT de vider la session (sinon le pare-feu le réécrit dans la session neuve)
            $this->tokens?->setToken(null);
            $session->invalidate();

            return new RedirectResponse('/?tenant=' . rawurlencode($entry['home']));
        }
        // une session NEUVE (nouvel identifiant, aucune clé de l'entrée : brouillons, messages, page visée…), puis le site, la marque du code
        // à son heure d'origine et le compte d'attache
        $session->invalidate();
        $session->set('_active_admin_tenant', $entry['home']);
        OtpSession::markValidatedAt($session, $account, $entry['home'], $back['otpAt']);
        $this->tokens->setToken(new \Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken($account, 'main', $account->getRoles()));

        return new RedirectResponse('/admin?tenant=' . rawurlencode($entry['home']));
    }

    /** Le compte qui peut ouvrir une entrée : un compte du site (jamais un opérateur), agence, plateforme ou directeur d'un groupe, hors d'une entrée en cours */
    private function entrant(Request $request): ?User
    {
        $user = $this->getUser();
        if (!$user instanceof User || AdminEntrySession::get($request->getSession()) !== null) {
            return null;
        }

        if (AgencyRoles::has($user->getRoles()) || PlatformAccessJournal::isPlatformAccount($user->getRoles())) {
            return $user;
        }

        // lot G-1b : un administrateur du siège d'un groupe (mode « groupe » des contextes) entre dans les boutiques du groupe
        return in_array('ROLE_ADMIN', $user->getRoles(), true) && $this->contexts->isGroupHead() ? $user : null;
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

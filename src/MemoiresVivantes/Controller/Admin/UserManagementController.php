<?php

namespace App\MemoiresVivantes\Controller\Admin;

use App\Security\RoleAssignmentPolicy;
use App\Entity\User;
use App\MemoiresVivantes\Services\UserPopulationResolver;
use App\MemoiresVivantes\Entity\Book;
use App\MemoiresVivantes\Services\FrontendUrlResolver;
use App\Services\EmailConfigurationService\EmailSenderService;
use Psr\Log\LoggerInterface;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use App\Security\MemoiresTeamVoter;
use App\Security\TeamRoles;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Équipe (13/11/2026) : la liste et l'invitation d'un client sont ouvertes au biographe de l'équipe (MEMOIRES_TEAM) ; tout le reste
 * (rôles, modification, suppression) reste à ROLE_ADMIN, méthode par méthode. Contrat : docs/memoires-vivantes.md, « Équipe ».
 */
#[Route('/api/memoires/admin/users')]
#[IsGranted(MemoiresTeamVoter::ATTRIBUTE)]
class UserManagementController extends AbstractController
{
    /** Taille de page par défaut et maximale de la liste des utilisateurs */
    private const PAGE_LIMIT = 200;

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly RoleAssignmentPolicy $rolePolicy,
        private readonly FrontendUrlResolver $frontendUrl,
        private readonly EmailSenderService $emailSender,
        private readonly LoggerInterface $logger,
        private readonly \App\MemoiresVivantes\Services\MobileUserStatsService $mobileUserStats,
        private readonly \App\Services\AccountService\SessionIdentityService $sessions, // jetons de renouvellement après un changement de courriel
        private readonly \App\MemoiresVivantes\UseCase\Team\SetBiographerUseCase $setBiographer,
        private readonly ?\App\Services\AppMembershipService\AppMembershipService $memberships = null // familles d'applications (APPS-MEMBRES M1, 14/11/2026)
    ) {}

    /** Adresse du site frontend (les liens d'activation y renvoient ; avant, ils pointaient vers le stockage des médias) */
    private function resolveHost(Request $request): string
    {
        return $this->frontendUrl->baseUrl($request);
    }

    #[Route('', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $em = $this->emProvider->getEntityManager();
        $userRepo = $em->getRepository(User::class);
        // Pagination (S8, 26/10/2026) : ?page= (défaut 1) et ?limit= (défaut et plafond 200), total dans X-Total-Count ; corps toujours un
        // tableau. Avant : tous les utilisateurs d'un coup. Sans paramètre : les 200 premiers (aujourd'hui, tous les utilisateurs d'un site)
        $limit = max(1, min(self::PAGE_LIMIT, (int) $request->query->get('limit', (string) self::PAGE_LIMIT)));
        $page = max(1, (int) $request->query->get('page', '1'));
        // Filtre serveur ?population=team|admin|mobile|web_client (13/11/2026) : la population est calculée, on la filtre après l'avoir
        // calculée sur tous les comptes du site, puis on paginera en mémoire ; sans filtre, pagination en base comme avant
        $population = $request->query->get('population');
        if ($population !== null && !in_array($population, UserPopulationResolver::ALL, true)) {
            return $this->json(['error' => 'population inconnue : team, admin, mobile ou web_client.', 'code' => 'POPULATION_INVALID'], Response::HTTP_BAD_REQUEST);
        }
        if ($population !== null) {
            $all = $userRepo->findBy([], ['id' => 'ASC']);
            $extra = $this->mobileUserStats->enrichment($all);
            $users = array_values(array_filter($all, fn (User $u) => ($extra[$u->getId()]['population'] ?? null) === $population));
            $total = count($users);
            $users = array_slice($users, ($page - 1) * $limit, $limit);
        } else {
            $total = $userRepo->count([]);
            $users = $userRepo->findBy([], ['id' => 'ASC'], $limit, ($page - 1) * $limit);
            $extra = $this->mobileUserStats->enrichment($users);
        }

        // Récupérer le nombre de livres créés par chaque utilisateur en une seule requête groupée
        $bookCounts = $em->getRepository(Book::class)->createQueryBuilder('b')
            ->select('IDENTITY(b.user) as userId, COUNT(b.id) as count')
            ->groupBy('b.user')
            ->getQuery()
            ->getResult();

        $countMap = [];
        foreach ($bookCounts as $row) {
            $countMap[$row['userId']] = (int) $row['count'];
        }

        $host = $this->resolveHost($request);
        // Champs ajoutés (16/10/2026) : population, origine, création, dernière activité, livres mobile ; (13/11/2026) isBiographer, teamRoles
        $data = [];

        foreach ($users as $u) {
            $isPending = !$u->isVerified() && !empty($u->getVerificationToken());
            $activationUrl = $isPending ? $host . '/activer-compte?token=' . $u->getVerificationToken() : null;

            $data[] = [
                'id' => $u->getId(),
                'email' => $u->getEmail(),
                'firstName' => $u->getFirstname(),
                'lastName' => $u->getLastname(),
                'fullName' => trim($u->getFirstname() . ' ' . $u->getLastname()),
                'username' => $u->getUsername(),
                'roles' => $u->getRoles(),
                'role' => in_array('ROLE_ADMIN', $u->getRoles(), true) ? 'ROLE_ADMIN' : 'ROLE_USER',
                'isBiographer' => in_array(TeamRoles::MEMOIRES_BIOGRAPHE, $u->getRoles(), true),
                'teamRoles' => TeamRoles::of($u->getRoles()),
                'isVerified' => $u->isVerified(),
                'isPending' => $isPending,
                'booksCount' => $countMap[$u->getId()] ?? 0,
                'population' => $extra[$u->getId()]['population'] ?? null,
                'signupChannel' => $extra[$u->getId()]['signupChannel'] ?? null,
                'createdAt' => $extra[$u->getId()]['createdAt'] ?? null,
                'lastActivityAt' => $extra[$u->getId()]['lastActivityAt'] ?? null,
                'mobileBooksCount' => $extra[$u->getId()]['mobileBooksCount'] ?? 0,
                'activationUrl' => $activationUrl,
            ];
        }

        return $this->json($data, 200, ['X-Total-Count' => (string) $total, 'Access-Control-Expose-Headers' => 'X-Total-Count']);
    }

    #[Route('', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];

        $email = trim($data['email'] ?? '');
        $firstName = trim($data['firstName'] ?? $data['firstname'] ?? '');
        $lastName = trim($data['lastName'] ?? $data['lastname'] ?? '');
        $role = $data['role'] ?? 'ROLE_USER';
        $password = !empty($data['password']) ? (string) $data['password'] : null;
        if ($role === 'ROLE_ADMIN' && !$this->isGranted('ROLE_ADMIN')) { // biographe de l'équipe : invitation d'un client seulement (13/11/2026)
            return $this->json(['error' => 'Seul un administrateur du site peut créer un administrateur.', 'code' => 'FORBIDDEN'], Response::HTTP_FORBIDDEN);
        }

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['error' => 'Une adresse email valide est requise.'], Response::HTTP_BAD_REQUEST);
        }
        if (empty($firstName) || empty($lastName)) {
            return $this->json(['error' => 'Le prénom et le nom sont requis.'], Response::HTTP_BAD_REQUEST);
        }

        $em = $this->emProvider->getEntityManager();
        $userRepo = $em->getRepository(User::class);

        // Vérifier l'unicité de l'email
        $existing = $userRepo->findOneBy(['email' => $email]);
        if ($existing) {
            return $this->json(['error' => 'Un compte existe déjà avec cette adresse email.'], Response::HTTP_CONFLICT);
        }

        $newUser = new User();
        $newUser->setEmail($email);
        $newUser->setUsername($email);
        $newUser->setFirstname($firstName);
        $newUser->setLastname($lastName);

        $assignedRoles = ($role === 'ROLE_ADMIN')
            ? ['ROLE_ADMIN', 'ROLE_USER', 'ROLE_USER_POS', 'ROLE_USER_INTERNET']
            : ['ROLE_USER', 'ROLE_USER_INTERNET'];
        $newUser->setRoles($assignedRoles);
        $newUser->setSignupChannel(User::SIGNUP_WEB_INVITE);

        $host = $this->resolveHost($request);
        $token = null;
        $activationUrl = null;

        if ($password !== null && strlen($password) >= 6) {
            // Mode création directe avec mot de passe immédiat
            $hashed = $this->passwordHasher->hashPassword($newUser, $password);
            $newUser->setPassword($hashed);
            $newUser->setIsVerified(true);
            $newUser->setVerificationToken(null);
        } else {
            // Mode invitation sécurisée avec lien d'activation
            $token = bin2hex(random_bytes(32));
            $dummySecret = bin2hex(random_bytes(20));
            $newUser->setPassword($this->passwordHasher->hashPassword($newUser, $dummySecret));
            $newUser->setIsVerified(false);
            $newUser->setVerificationToken($token);
            $activationUrl = $host . '/activer-compte?token=' . $token;
        }

        $em->persist($newUser);
        $em->flush();
        // APPS-MEMBRES M1 : un compte client créé par l'équipe est membre de la famille Mémoires Vivantes (source « invite »), sans
        // conditions acceptées ; un compte d'équipe (administrateur) n'est jamais membre : le service l'écarte.
        $this->memberships?->invited($newUser);

        $emailSent = false;
        if ($activationUrl !== null) {
            $emailSent = $this->sendInvitation($newUser, $activationUrl, $request);
        }

        return $this->json([
            'status' => 'created',
            'user' => [
                'id' => $newUser->getId(),
                'email' => $newUser->getEmail(),
                'firstName' => $newUser->getFirstname(),
                'lastName' => $newUser->getLastname(),
                'fullName' => trim($newUser->getFirstname() . ' ' . $newUser->getLastname()),
                'role' => ($role === 'ROLE_ADMIN') ? 'ROLE_ADMIN' : 'ROLE_USER',
                'isVerified' => $newUser->isVerified(),
                'isPending' => !$newUser->isVerified(),
                'booksCount' => 0,
                'token' => $token,
                'activationUrl' => $activationUrl,
                'emailSent' => $emailSent,
            ],
            'message' => $activationUrl
                ? ($emailSent
                    ? 'Invitation envoyée par e-mail. Le lien d\'activation reste disponible ci-dessous si besoin.'
                    : 'Compte créé, mais l\'e-mail d\'invitation n\'a pas pu être envoyé : transmettez le lien d\'activation vous-même.')
                : 'Utilisateur créé avec succès avec son mot de passe initial.'
        ], Response::HTTP_CREATED);
    }

    /** E-mail d'invitation par le serveur d'envoi du site ; un échec n'empêche pas la création du compte */
    private function sendInvitation(User $user, string $activationUrl, Request $request): bool
    {
        $inviter = $this->getUser();
        $invitedBy = trim(($inviter?->getFirstname() ?? '') . ' ' . ($inviter?->getLastname() ?? '')) ?: 'Votre biographe';
        try {
            return $this->emailSender->sendTemplatedEmail(
                $user->getEmail(),
                'Activez votre compte',
                'emails/memoires_account_invitation.html.twig',
                ['firstName' => $user->getFirstname() ?: 'bienvenue', 'email' => $user->getEmail(), 'activationUrl' => $activationUrl, 'invitedBy' => $invitedBy],
                (string) $request->query->get('locale', 'fr'),
                $request->getSchemeAndHttpHost()
            );
        } catch (\Throwable $e) {
            $this->logger->error('Invitation Mémoires Vivantes non envoyée : ' . $e->getMessage());

            return false;
        }
    }

    #[Route('/{id}', methods: ['PUT', 'PATCH'])]
    #[IsGranted('ROLE_ADMIN')]
    public function update(int $id, Request $request): JsonResponse
    {
        $em = $this->emProvider->getEntityManager();
        $userRepo = $em->getRepository(User::class);
        $targetUser = $userRepo->find($id);
        $previousEmail = $targetUser?->getEmail();

        if (!$targetUser) {
            return $this->json(['error' => 'Utilisateur introuvable.'], Response::HTTP_NOT_FOUND);
        }
        if ($denied = $this->protectOwner($targetUser)) {
            return $denied;
        }

        $data = json_decode($request->getContent(), true) ?? [];

        // Guardrail : interdiction de rétrograder le dernier admin
        if (isset($data['role'])) {
            $newRole = $data['role'];
            $wasAdmin = in_array('ROLE_ADMIN', $targetUser->getRoles(), true);
            if ($wasAdmin && $newRole !== 'ROLE_ADMIN') {
                $allUsers = $userRepo->findAll();
                $adminCount = count(array_filter($allUsers, fn($u) => in_array('ROLE_ADMIN', $u->getRoles(), true)));
                if ($adminCount <= 1) {
                    return $this->json(['error' => 'Impossible de rétrograder le dernier administrateur du compte.'], Response::HTTP_BAD_REQUEST);
                }
            }

            $assignedRoles = ($newRole === 'ROLE_ADMIN')
                ? ['ROLE_ADMIN', 'ROLE_USER', 'ROLE_USER_POS', 'ROLE_USER_INTERNET']
                : ['ROLE_USER', 'ROLE_USER_INTERNET'];
            $assignedRoles = [...$assignedRoles, ...TeamRoles::of($targetUser->getRoles())]; // les rôles d'équipe ne se gèrent qu'au point /team (13/11/2026)
            // Cet écran ne gère jamais ROLE_SUPER_ADMIN : conservé tel quel
            $targetUser->setRoles($this->rolePolicy->apply($assignedRoles, $targetUser->getRoles(), false));
        }

        if (isset($data['firstName']) || isset($data['firstname'])) {
            $targetUser->setFirstname(trim($data['firstName'] ?? $data['firstname']));
        }
        if (isset($data['lastName']) || isset($data['lastname'])) {
            $targetUser->setLastname(trim($data['lastName'] ?? $data['lastname']));
        }

        if (isset($data['email']) && trim($data['email']) !== '') {
            $newEmail = trim($data['email']);
            if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
                return $this->json(['error' => 'Format d\'email invalide.'], Response::HTTP_BAD_REQUEST);
            }
            if ($newEmail !== $targetUser->getEmail()) {
                $conflict = $userRepo->findOneBy(['email' => $newEmail]);
                if ($conflict && $conflict->getId() !== $targetUser->getId()) {
                    return $this->json(['error' => 'Cette adresse email est déjà utilisée par un autre compte.'], Response::HTTP_CONFLICT);
                }
                // Nom d'utilisateur égal à l'ancienne adresse : il suit la nouvelle, unicité vérifiée (suite de S7, 27/10/2026 ; avant, la
                // comparaison se faisait après le changement et ne réussissait jamais)
                $followsEmail = $targetUser->getUsername() === $targetUser->getEmail();
                if ($followsEmail && ($taken = $userRepo->findOneBy(['username' => $newEmail])) && $taken->getId() !== $targetUser->getId()) {
                    return $this->json(['error' => 'Cette adresse email est déjà utilisée par un autre compte.'], Response::HTTP_CONFLICT);
                }
                $targetUser->setEmail($newEmail);
                if ($followsEmail) {
                    $targetUser->setUsername($newEmail);
                }
            }
        }

        if (!empty($data['password'])) {
            if (strlen($data['password']) < 6) {
                return $this->json(['error' => 'Le mot de passe doit comporter au moins 6 caractères.'], Response::HTTP_BAD_REQUEST);
            }
            $targetUser->setPassword($this->passwordHasher->hashPassword($targetUser, $data['password']))->setPasswordSet(true); // mot de passe utilisable : confirmation par mot de passe
        }

        $em->flush();
        // Courriel changé par l'administrateur : les jetons de renouvellement de l'utilisateur suivent la nouvelle adresse (son jeton en
        // cours expire et son application le renouvelle) ; aucun jeton rendu, ce n'est pas sa session (suite de S7)
        if ($previousEmail !== null && $previousEmail !== $targetUser->getEmail()) {
            $this->sessions->transferRefreshTokens($previousEmail, (string) $targetUser->getEmail());
        }

        return $this->json([
            'status' => 'updated',
            'user' => [
                'id' => $targetUser->getId(),
                'email' => $targetUser->getEmail(),
                'firstName' => $targetUser->getFirstname(),
                'lastName' => $targetUser->getLastname(),
                'fullName' => trim($targetUser->getFirstname() . ' ' . $targetUser->getLastname()),
                'role' => in_array('ROLE_ADMIN', $targetUser->getRoles(), true) ? 'ROLE_ADMIN' : 'ROLE_USER',
                'isVerified' => $targetUser->isVerified(),
            ]
        ]);
    }

    /**
     * Équipe (13/11/2026) : pose ou retire le rôle de biographe ; corps {biographer: true|false} ; idempotent ; journal content_audit_log
     * (user_team, grant | revoke). → {id, isBiographer, teamRoles, roles, changed}. 400 INVALID_BODY, 403 OWNER_PROTECTED, 404 USER_NOT_FOUND.
     */
    #[Route('/{id}/team', methods: ['PUT'])]
    #[IsGranted('ROLE_ADMIN')]
    public function team(int $id, Request $request): JsonResponse
    {
        $targetUser = $this->emProvider->getEntityManager()->getRepository(User::class)->find($id);
        if (!$targetUser) {
            return $this->json(['error' => 'Utilisateur introuvable.', 'code' => 'USER_NOT_FOUND'], Response::HTTP_NOT_FOUND);
        }
        if ($denied = $this->protectOwner($targetUser)) {
            return $denied;
        }
        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !array_key_exists('biographer', $data) || !is_bool($data['biographer'])) {
            return $this->json(['error' => 'Corps attendu : {"biographer": true|false}.', 'code' => 'INVALID_BODY'], Response::HTTP_BAD_REQUEST);
        }

        return $this->json($this->setBiographer->execute($targetUser, $data['biographer']));
    }

    #[Route('/{id}', methods: ['DELETE'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(int $id): JsonResponse
    {
        $em = $this->emProvider->getEntityManager();
        $userRepo = $em->getRepository(User::class);
        $targetUser = $userRepo->find($id);

        if (!$targetUser) {
            return $this->json(['error' => 'Utilisateur introuvable.'], Response::HTTP_NOT_FOUND);
        }
        if ($denied = $this->protectOwner($targetUser)) {
            return $denied;
        }

        // Guardrail : interdiction de supprimer son propre compte
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        if ($currentUser && $currentUser->getId() === $targetUser->getId()) {
            return $this->json(['error' => 'Vous ne pouvez pas supprimer votre propre compte administrateur connecté.'], Response::HTTP_BAD_REQUEST);
        }

        // Guardrail : interdiction de supprimer le dernier admin
        if (in_array('ROLE_ADMIN', $targetUser->getRoles(), true)) {
            $allUsers = $userRepo->findAll();
            $adminCount = count(array_filter($allUsers, fn($u) => in_array('ROLE_ADMIN', $u->getRoles(), true)));
            if ($adminCount <= 1) {
                return $this->json(['error' => 'Impossible de supprimer le dernier administrateur du compte.'], Response::HTTP_BAD_REQUEST);
            }
        }

        // Guardrail : vérifier les livres existants
        $booksCount = $em->getRepository(Book::class)->count(['user' => $targetUser]);
        if ($booksCount > 0) {
            return $this->json([
                'error' => "Cet utilisateur possède {$booksCount} livre(s). Pour préserver l'historique littéraire, veuillez réassigner ou archiver ces livres avant de supprimer l'utilisateur."
            ], Response::HTTP_BAD_REQUEST);
        }

        $em->remove($targetUser);
        $em->flush();

        return $this->json([
            'status' => 'success',
            'message' => 'Utilisateur supprimé avec succès.'
        ]);
    }

    /** Compte du propriétaire de la plateforme (ROLE_SUPER_ADMIN) : modifiable ou supprimable par un super admin seulement */
    private function protectOwner(User $targetUser): ?JsonResponse
    {
        if (in_array(RoleAssignmentPolicy::SUPER_ADMIN, $targetUser->getRoles(), true) && !$this->isGranted(RoleAssignmentPolicy::SUPER_ADMIN)) {
            return $this->json(['error' => 'Compte du propriétaire de la plateforme : modification réservée.', 'code' => 'OWNER_PROTECTED'], Response::HTTP_FORBIDDEN);
        }

        return null;
    }
}

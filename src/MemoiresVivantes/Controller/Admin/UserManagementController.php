<?php

namespace App\MemoiresVivantes\Controller\Admin;

use App\Security\RoleAssignmentPolicy;
use App\Entity\User;
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
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/memoires/admin/users')]
#[IsGranted('ROLE_ADMIN')]
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
        private readonly \App\Services\AccountService\SessionIdentityService $sessions // jetons de renouvellement après un changement de courriel
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
        $total = $userRepo->count([]);
        $users = $userRepo->findBy([], ['id' => 'ASC'], $limit, ($page - 1) * $limit);

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
        // Champs ajoutés (16/10/2026) : population, origine, création, dernière activité, livres mobile
        $extra = $this->mobileUserStats->enrichment($users);
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
            $targetUser->setPassword($this->passwordHasher->hashPassword($targetUser, $data['password']));
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

    #[Route('/{id}', methods: ['DELETE'])]
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
            return $this->json(['error' => 'Compte du propriétaire de la plateforme : modification réservée.'], Response::HTTP_FORBIDDEN);
        }

        return null;
    }
}

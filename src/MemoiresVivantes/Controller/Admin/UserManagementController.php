<?php

namespace App\MemoiresVivantes\Controller\Admin;

use App\Entity\User;
use App\MemoiresVivantes\Entity\Book;
use App\Services\MediaUrlResolver;
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
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly ?MediaUrlResolver $mediaUrlResolver = null
    ) {}

    private function resolveHost(Request $request): string
    {
        return $this->mediaUrlResolver?->getPublicHost($request->getSchemeAndHttpHost())
            ?? $request->getSchemeAndHttpHost();
    }

    #[Route('', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $em = $this->emProvider->getEntityManager();
        $userRepo = $em->getRepository(User::class);
        $users = $userRepo->findBy([], ['id' => 'ASC']);

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
                'activationUrl' => $activationUrl,
            ];
        }

        return $this->json($data);
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
            ],
            'message' => $activationUrl
                ? 'Collaborateur invité avec succès. Transmettez-lui le lien d\'activation.'
                : 'Utilisateur créé avec succès avec son mot de passe initial.'
        ], Response::HTTP_CREATED);
    }

    #[Route('/{id}', methods: ['PUT', 'PATCH'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $em = $this->emProvider->getEntityManager();
        $userRepo = $em->getRepository(User::class);
        $targetUser = $userRepo->find($id);

        if (!$targetUser) {
            return $this->json(['error' => 'Utilisateur introuvable.'], Response::HTTP_NOT_FOUND);
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
            $targetUser->setRoles($assignedRoles);
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
                $targetUser->setEmail($newEmail);
                if ($targetUser->getUsername() === $targetUser->getEmail()) {
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
}

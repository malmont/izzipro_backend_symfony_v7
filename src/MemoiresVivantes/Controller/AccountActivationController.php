<?php

namespace App\MemoiresVivantes\Controller;

use App\Entity\User;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/memoires/auth')]
class AccountActivationController extends AbstractController
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly UserPasswordHasherInterface $passwordHasher
    ) {}

    #[Route('/activate', methods: ['POST'])]
    public function activate(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $token = trim($data['token'] ?? '');
        $password = (string) ($data['password'] ?? '');

        if (empty($token)) {
            return $this->json(['error' => 'Jeton d\'activation requis.'], Response::HTTP_BAD_REQUEST);
        }

        if (empty($password) || strlen($password) < 6) {
            return $this->json(['error' => 'Le mot de passe doit comporter au moins 6 caractères.'], Response::HTTP_BAD_REQUEST);
        }

        $em = $this->emProvider->getEntityManager();
        $userRepo = $em->getRepository(User::class);

        /** @var User|null $user */
        $user = $userRepo->findOneBy(['verificationToken' => $token]);

        if (!$user) {
            return $this->json(['error' => 'Ce lien d\'activation est invalide ou a déjà été utilisé.'], Response::HTTP_NOT_FOUND);
        }

        $hashedPassword = $this->passwordHasher->hashPassword($user, $password);
        $user->setPassword($hashedPassword);
        $user->setIsVerified(true);
        $user->setVerificationToken(null);

        $em->flush();

        return $this->json([
            'status' => 'success',
            'message' => 'Votre compte a été activé avec succès ! Vous pouvez maintenant vous connecter.',
            'email' => $user->getEmail()
        ]);
    }
}

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
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly ?\App\Import\ImportGuests $importGuests = null, // lot IMP-2 : comptes invités par import
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

        // lot IMP-2 : un invité par import ne s'active que par son invitation (conditions de la boutique acceptées)
        // Train de sécurité du 16/11/2026 : cette route POSE UN MOT DE PASSE. Elle ne vaut donc que pour un compte né d'une invitation
        // de la console, pas encore activé, dont le lien n'a pas expiré (7 jours). Avant, tout compte non vérifié s'y activait par son
        // jeton, y compris un client inscrit par la boutique (dont le jeton ne sert qu'à vérifier son adresse), et sans échéance.
        if (!$user || $this->importGuests?->isPending($user) || !$user->canActivateByLink()) {
            return $this->json(['error' => 'Ce lien d\'activation est invalide, expiré ou a déjà été utilisé.', 'code' => 'ACTIVATION_LINK_INVALID'], Response::HTTP_NOT_FOUND);
        }

        $hashedPassword = $this->passwordHasher->hashPassword($user, $password);
        $user->setPassword($hashedPassword)->setPasswordSet(true); // mot de passe choisi à l'activation de l'invitation
        $user->setIsVerified(true);
        $user->setVerificationToken(null)->setVerificationTokenExpiresAt(null); // une seule fois

        $em->flush();

        return $this->json([
            'status' => 'success',
            'message' => 'Votre compte a été activé avec succès ! Vous pouvez maintenant vous connecter.',
            'email' => $user->getEmail()
        ]);
    }
}

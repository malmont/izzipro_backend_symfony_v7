<?php
// src/Services/AuthenticationService.php

namespace App\Services;

use App\Entity\User;
use App\Services\TokenService;
use App\Services\OtpService;
// MODIFICATION : On importe notre provider
use App\Services\TenantEntityManagerProvider; 
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AuthenticationService
{
    // MODIFICATION 1 : Simplification du constructeur
    // On enlève ManagerRegistry et TenantStateService
    // On ajoute TenantEntityManagerProvider
    public function __construct(
        private TenantEntityManagerProvider $emProvider,
        private UserPasswordHasherInterface $passwordHasher,
        private OtpService $otpService,
        private TokenService $tokenService,
    ) {}

    /**
     * MODIFICATION 2 : La méthode complexe est entièrement supprimée.
     * Elle n'est plus nécessaire grâce au TenantEntityManagerProvider.
     */
    // private function createIsolatedEntityManager(): EntityManagerInterface { ... }

    public function login(string $email, string $password, string $platform, Request $request): Response
    {
 
        $em = $this->emProvider->getEntityManager();
        
        $dbName = $em->getConnection()->getDatabase();
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);

        if (!$user instanceof UserInterface) {
            return new JsonResponse(
                ['error' => 'Unauthorized'],             
                Response::HTTP_UNAUTHORIZED
            );
        }

        if (!$user instanceof UserInterface || !$this->passwordHasher->isPasswordValid($user, $password)) {
            return new JsonResponse(
                ['code' => 'LOGIN_INVALID', 'message' => 'Identifiants invalides'], 
                Response::HTTP_UNAUTHORIZED
            );
        }
        
        if (!$user->isVerified()) {
            return new JsonResponse(
                ['code' => 'ACCOUNT_NOT_VERIFIED', 'message' => 'Votre compte n\'est pas vérifié. Veuillez consulter vos e-mails.'],
                Response::HTTP_UNAUTHORIZED
            );
        }
        
        if (in_array($platform, ['web', 'mobile'])) {
            if (!in_array('ROLE_USER_INTERNET', $user->getRoles(), true)) {
               return new JsonResponse(
                    ['error' => 'This account is not allowed to access the web/mobile platform.'],
                    Response::HTTP_FORBIDDEN
                );
            }
        } elseif ($platform === 'pos') {
            if (!in_array('ROLE_USER_POS', $user->getRoles(), true)) {
                return new JsonResponse(
                    ['error' => 'This account is not allowed to access the POS platform.'],
                    Response::HTTP_FORBIDDEN
                );
            }
        }

        if ($user->isOtpEnabled()) {
            $this->otpService->generateAndSendOtp($user, $request);
            return new Response(
                json_encode([
                    'otp_required' => true,
                    'message' => 'Un code OTP vous a été envoyé par email. Veuillez le saisir pour continuer.'
                ]),
                Response::HTTP_OK,
                ['Content-Type' => 'application/json']
            );
        }
        
        $tokens = $this->tokenService->generateTokens($user);
        $host = $request->getHost();
        return $this->tokenService->createResponseWithTokens($tokens, $platform,$host);
    }
}
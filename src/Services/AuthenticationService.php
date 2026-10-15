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
    /** Plateformes de connexion connues : web (cookies), mobile, pos (caisse) et admin (application de gestion, 15/10/2026) */
    public const PLATFORMS = ['web', 'mobile', 'pos', 'admin'];

    public function __construct(
        private TenantEntityManagerProvider $emProvider,
        private UserPasswordHasherInterface $passwordHasher,
        private OtpService $otpService,
        private TokenService $tokenService,
        private \App\Security\TwoFactorPolicy $twoFactor,
        private \Psr\Log\LoggerInterface $logger,
        private TenantConnectionProvider $tenantConnectionProvider,
    ) {}

    /**
     * Valeur platform normalisée (trim, minuscules) ou null si elle est inconnue (15/10/2026 : avant, une valeur
     * inconnue sautait tout contrôle de rôle et obtenait des jetons). Journalisée pour repérer un client oublié.
     */
    public function normalizePlatform(string $platform, string $context): ?string
    {
        $normalized = strtolower(trim($platform));
        if (!in_array($normalized, self::PLATFORMS, true)) {
            $this->logger->warning(sprintf('[%s] platform inconnue refusée : « %s » (site %s)', $context, $platform, $this->tenantConnectionProvider->getTenantCode() ?? 'inconnu'));
            return null;
        }

        return $normalized;
    }

    /** Rôle exigé par la plateforme : web et mobile → ROLE_USER_INTERNET, pos → ROLE_USER_POS, admin → ROLE_ADMIN ; null si tout va bien */
    public function platformRoleViolation(User $user, string $platform): ?JsonResponse
    {
        if (in_array($platform, ['web', 'mobile'], true)) {
            if (!in_array('ROLE_USER_INTERNET', $user->getRoles(), true)) {
                return new JsonResponse(['error' => 'This account is not allowed to access the web/mobile platform.'], Response::HTTP_FORBIDDEN);
            }
        } elseif ($platform === 'pos') {
            if (!in_array('ROLE_USER_POS', $user->getRoles(), true)) {
                return new JsonResponse(['error' => 'This account is not allowed to access the POS platform.'], Response::HTTP_FORBIDDEN);
            }
        } elseif ($platform === 'admin') {
            if (!in_array('ROLE_ADMIN', $user->getRoles(), true)) {
                return new JsonResponse(['error' => 'This account is not allowed to access the admin platform.'], Response::HTTP_FORBIDDEN);
            }
        }

        return null;
    }

    public static function invalidPlatformResponse(): JsonResponse
    {
        return new JsonResponse(['error' => 'PLATFORM_INVALID', 'message' => 'Plateforme de connexion inconnue'], Response::HTTP_BAD_REQUEST);
    }

    /**
     * MODIFICATION 2 : La méthode complexe est entièrement supprimée.
     * Elle n'est plus nécessaire grâce au TenantEntityManagerProvider.
     */
    // private function createIsolatedEntityManager(): EntityManagerInterface { ... }

    public function login(string $email, string $password, string $platform, Request $request): Response
    {
        $platform = $this->normalizePlatform($platform, 'login');
        if ($platform === null) {
            return self::invalidPlatformResponse();
        }

        $em = $this->emProvider->getEntityManager();

        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);

        if (!$user instanceof User || !$this->passwordHasher->isPasswordValid($user, $password)) {
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

        if ($refused = $this->platformRoleViolation($user, $platform)) {
            return $refused;
        }

        if ($this->twoFactor->required($user)) {
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

        $tokens = $this->tokenService->generateTokens($user, $platform);
        $host = $request->getHost();
        return $this->tokenService->createResponseWithTokens($tokens, $platform, $host);
    }
}

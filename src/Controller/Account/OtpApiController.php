<?php
// src/Controller/Account/OtpApiController.php

namespace App\Controller\Account;

use App\Entity\User;
use App\Entity\OtpCode;
use DateTime;
use App\Services\TenantEntityManagerProvider;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use App\Services\TokenService;
use App\Services\AuthenticationService;
use Symfony\Component\HttpFoundation\RequestStack;

class OtpApiController extends AbstractController
{
    private TenantEntityManagerProvider $emProvider;
    private TokenService $tokenService;
    private $session;

    public function __construct(
        TenantEntityManagerProvider $emProvider,
        TokenService $tokenService,
        RequestStack $requestStack,
        private readonly AuthenticationService $authenticationService
    ) {
        $this->emProvider   = $emProvider;
        $this->tokenService = $tokenService;
        $this->session      = $requestStack->getSession();
    }

    #[Route(path: '/api/otp-verify', name: 'api_otp_verify', methods: ['POST'])]
    public function otpVerifyApi(Request $request): Response
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $email = $data['username'] ?? '';
        $otpProvided = $data['otp'] ?? '';
        // Absente → « mobile » comme avant ; valeur explicite inconnue → 400 PLATFORM_INVALID (15/10/2026)
        $platform = $this->authenticationService->normalizePlatform((string) ($data['platform'] ?? 'mobile'), 'otp-verify');
        if ($platform === null) {
            return AuthenticationService::invalidPlatformResponse();
        }
        $em = $this->emProvider->getEntityManager();
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        if (!$user instanceof UserInterface) {
            return $this->json([
                'error' => 'Unauthorized'
            ], Response::HTTP_UNAUTHORIZED);
        }

        $otpCode = $em->getRepository(OtpCode::class)->findOneBy([
            'userOtp' => $user,
            'code'    => $otpProvided
        ]);

        if (!$otpCode || $otpCode->getExpiration() < new DateTime()) {
            $this->authenticationService->auditPos($platform, null, (string) $email, 'invalid_otp');
            return $this->json([
                'error' => 'Code OTP invalide ou expiré'
            ], Response::HTTP_UNAUTHORIZED);
        }

        // Plateforme « admin » (application de gestion, 15/10/2026) : ROLE_ADMIN exigé ; les autres valeurs ne sont pas
        // contrôlées ici (comme avant : le rôle a été vérifié à l'étape de connexion)
        if ($platform === 'admin' && $user instanceof User && ($refused = $this->authenticationService->platformRoleViolation($user, $platform))) {
            return $refused;
        }

        $em->remove($otpCode);
        $em->flush();
        if ($user instanceof User) {
            $this->authenticationService->recordLogin($user);
            $this->authenticationService->auditPos($platform, $user, (string) $email);
        }

        $tokens = $this->tokenService->generateTokens($user, $platform);

        return $this->tokenService->createResponseWithTokens($tokens, $platform, $request->getHost());
    }
}

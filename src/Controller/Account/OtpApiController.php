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
            // Courriel inconnu : même réponse que pour un code faux (B16, 04/11/2026), pour ne pas révéler si un compte existe
            // (avant : 401 UNAUTHORIZED) ; audité comme un code faux
            $this->authenticationService->auditPos($platform, null, (string) $email, 'invalid_otp');

            return $this->json([
                'error' => 'Code OTP invalide ou expiré', 'code' => 'OTP_INVALID', 'message' => 'Code OTP invalide ou expiré',
            ], Response::HTTP_UNAUTHORIZED);
        }

        // Codes de connexion seulement (01/11/2026) : un code de changement de courriel ou de suppression n'ouvre pas de session
        $otpCode = $em->getRepository(OtpCode::class)->findOneBy([
            'userOtp' => $user,
            'code'    => $otpProvided,
            'purpose' => OtpCode::PURPOSE_LOGIN,
        ]);

        if (!$otpCode || $otpCode->getExpiration() < new DateTime()) {
            $this->authenticationService->auditPos($platform, null, (string) $email, 'invalid_otp');
            return $this->json([
                'error' => 'Code OTP invalide ou expiré', 'code' => 'OTP_INVALID', 'message' => 'Code OTP invalide ou expiré',
            ], Response::HTTP_UNAUTHORIZED);
        }

        // Plateforme « admin » (application de gestion, 15/10/2026) : ROLE_ADMIN exigé ; les autres valeurs ne sont pas
        // contrôlées ici (comme avant : le rôle a été vérifié à l'étape de connexion)
        if ($platform === 'admin' && $user instanceof User && ($refused = $this->authenticationService->platformRoleViolation($user, $platform))) {
            return $refused;
        }

        $em->remove($otpCode);
        $em->flush();
        // Mémoires Vivantes web est l'outil de l'équipe ; puis APPS-MEMBRES M3 : en mode « require », un compte client qui n'est pas membre
        // de la famille demandée passe par « Rejoindre »
        if ($refused = $this->authenticationService->memoiresWebRefusal($user, $data['app'] ?? null, 'otp-verify')
            ?? $this->authenticationService->membershipRefusal($user, $data['app'] ?? null, $platform, 'otp-verify', $request)) {
            return $refused;
        }
        if ($user instanceof User) {
            $this->authenticationService->recordLogin($user);
            $this->authenticationService->auditPos($platform, $user, (string) $email);
        }

        // `app` (facultatif, APPS-MEMBRES M1) : famille d'applications de la session, portée par le jeton ; sans lui rien ne change
        $tokens = $this->tokenService->generateTokens($user, $platform, $this->authenticationService->appFamily($user, $data['app'] ?? null, 'otp-verify'));

        return $this->tokenService->createResponseWithTokens($tokens, $platform, $request->getHost());
    }
}

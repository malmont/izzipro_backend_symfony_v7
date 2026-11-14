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
        private ?\App\Services\PosAuditService\PosAuditService $posAudit = null,
        private ?\App\Services\AppMembershipService\AppMembershipService $memberships = null, // familles d'applications (APPS-MEMBRES M1, 14/11/2026)
    ) {}

    /**
     * Famille d'applications de la session demandée (`app` de la connexion, de la vérification du code ou du rafraîchissement), à
     * porter dans le jeton ; null sans `app` connu : rien ne change. Mode « observer » (M1) : un compte client qui n'est pas membre
     * de cette famille est noté au journal et reçoit sa session quand même. Contrat : docs/apps-membres.md § 5.
     */
    /**
     * Le refus `APP_MEMBERSHIP_REQUIRED` (403), ou null : la session peut être rendue. Seulement en mode « require » du site, pour
     * un compte client dont le front a dit l'application (lot M3, docs/apps-membres.md § 6). À appeler au moment de rendre la
     * session, AVANT de marquer la connexion : un refus ne rend ni jeton, ni cookie, et ne compte pas comme une connexion.
     */
    public function membershipRefusal(UserInterface $user, mixed $app, string $platform, string $context, Request $request): ?JsonResponse
    {
        $body = $user instanceof User ? $this->memberships?->gate($user, $app, $platform, $context, $request->getSchemeAndHttpHost(), $request->query->get('locale')) : null;

        return $body === null ? null : new JsonResponse($body, Response::HTTP_FORBIDDEN);
    }

    public function appFamily(UserInterface $user, mixed $app, string $context): ?string
    {
        return $user instanceof User ? $this->memberships?->sessionFamily($user, $app, $context) : null;
    }

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
                return new JsonResponse(['error' => 'This account is not allowed to access the web/mobile platform.', 'code' => 'PLATFORM_NOT_ALLOWED', 'message' => 'This account is not allowed to access the web/mobile platform.'], Response::HTTP_FORBIDDEN);
            }
        } elseif ($platform === 'pos') {
            if (!array_intersect(['ROLE_USER_POS', 'ROLE_POS_MANAGER'], $user->getRoles())) {
                return new JsonResponse(['error' => 'This account is not allowed to access the POS platform.', 'code' => 'PLATFORM_NOT_ALLOWED', 'message' => 'This account is not allowed to access the POS platform.'], Response::HTTP_FORBIDDEN);
            }
        } elseif ($platform === 'admin') {
            // Assistance, lot 1 (12/11/2026) : un compte ROLE_SUPPORT se connecte à l'application de gestion ; le serveur ne lui ouvre que /api/support
            if (!array_intersect(['ROLE_ADMIN', 'ROLE_SUPPORT'], $user->getRoles())) {
                return new JsonResponse(['error' => 'This account is not allowed to access the admin platform.', 'code' => 'PLATFORM_NOT_ALLOWED', 'message' => 'This account is not allowed to access the admin platform.'], Response::HTTP_FORBIDDEN);
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

    /** Dernière connexion réussie (16/10/2026) ; ne doit jamais faire échouer la connexion */
    public function recordLogin(User $user): void
    {
        try {
            $user->setLastLoginAt(new \DateTimeImmutable());
            $this->emProvider->getEntityManager()->flush();
        } catch (\Throwable $e) {
            $this->logger->warning('Dernière connexion non enregistrée : ' . $e->getMessage());
        }
    }

    public function login(string $email, string $password, string $platform, Request $request, mixed $app = null): Response
    {
        $platform = $this->normalizePlatform($platform, 'login');
        if ($platform === null) {
            return self::invalidPlatformResponse();
        }

        $em = $this->emProvider->getEntityManager();

        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);

        if (!$user instanceof User || $user->isDeleted() || !$this->passwordHasher->isPasswordValid($user, $password)) {
            $this->auditPos($platform, null, $email, 'invalid_credentials');
            return new JsonResponse(
                ['code' => 'LOGIN_INVALID', 'message' => 'Identifiants invalides'],
                Response::HTTP_UNAUTHORIZED
            );
        }

        if (!$user->isVerified()) {
            $this->auditPos($platform, null, $email, 'not_verified');
            return new JsonResponse(
                ['code' => 'ACCOUNT_NOT_VERIFIED', 'message' => 'Votre compte n\'est pas vérifié. Veuillez consulter vos e-mails.'],
                Response::HTTP_UNAUTHORIZED
            );
        }

        if ($refused = $this->platformRoleViolation($user, $platform)) {
            $this->auditPos($platform, null, $email, 'platform_not_allowed');
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

        if ($refused = $this->membershipRefusal($user, $app, $platform, 'login', $request)) {
            return $refused;
        }
        $this->recordLogin($user);
        $this->auditPos($platform, $user, $email);
        $tokens = $this->tokenService->generateTokens($user, $platform, $this->appFamily($user, $app, 'login'));
        $host = $request->getHost();
        return $this->tokenService->createResponseWithTokens($tokens, $platform, $host);
    }

    /**
     * Journal de caisse (C2) : connexion réussie (LOGIN) ou refusée (LOGIN_FAILED, e-mail seulement, jamais le mot de
     * passe) sur la plateforme « pos ». Rien pour les autres plateformes. Une erreur du journal ne bloque pas la connexion.
     */
    public function auditPos(string $platform, ?User $user, string $email, ?string $failure = null): void
    {
        if ($platform !== 'pos' || $this->posAudit === null) {
            return;
        }
        try {
            $failure === null
                ? $this->posAudit->record(\App\Services\PosAuditService\PosAuditService::LOGIN, ['email' => $email], null, null, 'user', $user?->getId(), $user)
                : $this->posAudit->record(\App\Services\PosAuditService\PosAuditService::LOGIN_FAILED, ['email' => mb_substr($email, 0, 180), 'reason' => $failure], anonymous: true);
        } catch (\Throwable) {
            // journal indisponible : la connexion n'en dépend pas
        }
    }
}

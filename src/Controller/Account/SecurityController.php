<?php

namespace App\Controller\Account;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Gesdinet\JWTRefreshTokenBundle\Entity\RefreshToken;
use DateTime;
use Symfony\Component\Mime\Email;
use App\Services\TokenService;
use App\Entity\OtpCode;
use App\Entity\Entreprise;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use App\Services\OtpService;
use App\Entity\User;
use App\Services\AuthenticationService;
use App\Services\TenantEntityManagerProvider;
use App\Services\TenantConnectionProvider;

class SecurityController extends AbstractController
{
    private TenantEntityManagerProvider $tenantEmProvider;
    private TenantConnectionProvider $tenantConnProvider;
    private TokenService $tokenService;
    private OtpService $otpService;

    public function __construct(
        TenantEntityManagerProvider $tenantEmProvider,
        TenantConnectionProvider $tenantConnProvider,
        TokenService $tokenService,
        OtpService $otpService
    ) {
        $this->tenantEmProvider = $tenantEmProvider;
        $this->tenantConnProvider = $tenantConnProvider;
        $this->tokenService  = $tokenService;
        $this->otpService    = $otpService;
    }


    #[Route(path: '/', name: 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        $error = $authenticationUtils->getLastAuthenticationError();
        $lastUsername = $authenticationUtils->getLastUsername();

        return $this->render('security/login.html.twig', ['last_username' => $lastUsername, 'error' => $error]);
    }

    #[Route(path: '/logout', name: 'app_logout')]
    public function logout(): void
    {
        throw new \LogicException('This method can be blank - it will be intercepted by the logout key on your firewall.');
    }

    #[Route(path: '/api/login', name: 'api_login', methods: ['POST'])]
    public function loginApi(Request $request, AuthenticationService $auth): Response
    {
        $data = json_decode($request->getContent(), true) ?? [];
        return $auth->login(
            $data['username']  ?? '',
            $data['password']  ?? '',
            $data['platform']  ?? 'mobile',
            $request
        );
    }


    #[Route('/api/token/refresh', name: 'api_token_refresh', methods: ['POST'])]
    public function refreshToken(
        Request $request,
        JWTTokenManagerInterface $JWTManager,
        RefreshTokenManagerInterface $refreshTokenManager
    ): Response {
        // Isolation Tenant par Cookie Name
        $tenantCode = $this->tenantConnProvider->getTenantCode() ?? 'default';
        $refreshName = 'refresh_token_' . $tenantCode;

        // Migration: On lit le cookie strict
        $refreshToken = $request->cookies->get($refreshName);
        if (!$refreshToken) {
            // Fallback temporaire ? Non, sécurité stricte requise.
            return $this->json(['error' => 'Refresh token not found'], Response::HTTP_UNAUTHORIZED);
        }

        $validRefreshToken = $refreshTokenManager->get($refreshToken);
        if (!$validRefreshToken || !$validRefreshToken->isValid()) {
            return $this->json(['error' => 'Invalid refresh token'], Response::HTTP_UNAUTHORIZED);
        }

        // Fix: RefreshToken entity has getUsername(), not getUser()
        $username = $validRefreshToken->getUsername();
        if (!$username) {
            return $this->json(['error' => 'User identity not found in token'], Response::HTTP_UNAUTHORIZED);
        }

        $em = $this->tenantEmProvider->getEntityManager();
        $user = $em->getRepository(User::class)->findOneBy(['email' => $username]); // Assuming username is email in this app logic

        if (!$user instanceof UserInterface) {
            return $this->json(['error' => 'User not found'], Response::HTTP_UNAUTHORIZED);
        }

        $newToken = $JWTManager->create($user);

        $response = $this->json([
            'message' => 'Token refreshed successfully'
        ], Response::HTTP_OK);

        // Fix: Isoler le cookie au domaine (Tenant isolation)
        $host = $request->getHost();
        $cookieDomain = ($host === 'localhost') ? null : $host;

        $tenantCode = $this->tenantConnProvider->getTenantCode() ?? 'default';
        $jwtName = 'auth_token_' . $tenantCode;


        $response->headers->setCookie(
            Cookie::create($jwtName)
                ->withValue($newToken)
                ->withHttpOnly(true)
                ->withSecure(true)
                ->withSameSite(Cookie::SAMESITE_NONE)
                ->withExpires(time() + 3600)
                ->withDomain($cookieDomain)
        );

        // Update CSRF token on refresh
        $csrfTokenValue = bin2hex(random_bytes(32));
        $response->headers->setCookie(
            Cookie::create('XSRF-TOKEN')
                ->withValue($csrfTokenValue)
                ->withHttpOnly(false)
                ->withSecure(true)
                ->withSameSite(Cookie::SAMESITE_NONE)
                ->withExpires(time() + 3600)
                ->withDomain($cookieDomain)
        );

        return $response;
    }

    #[Route('/api/validate-token', name: 'api_validate_token', methods: ['GET'])]
    public function validateToken(): Response
    {
        $user = $this->getUser();
        if ($user instanceof UserInterface) {
            return $this->json([
                'status' => 'success',
                'message' => 'Token is valid',
            ], 200);
        }
        return $this->json([
            'status' => 'error',
            'message' => 'Token is invalid or expired',
        ], 401);
    }


    #[Route(path: '/api/logout', name: 'api_logout', methods: ['POST'])]
    public function logoutWeb(Request $request): Response
    {
        $domain = $request->getHost();
        $response = $this->json([
            'message' => 'Successfully logged out',
        ]);

        // Fix: Tenant isolation domain
        $host = $request->getHost();
        $cookieDomain = ($host === 'localhost') ? null : $host;

        // Retour à la méthode clearCookie qui fonctionnait, en s'assurant des paramètres EXACTS
        // Path: '/', Domain: $cookieDomain, Secure: true, HttpOnly: true, SameSite: None

        $tenantCode = $this->tenantConnProvider->getTenantCode() ?? 'default';
        $jwtName = 'auth_token_' . $tenantCode;
        $refreshName = 'refresh_token_' . $tenantCode;

        // 1. Suppression Cookies du Tenant Courant
        $response->headers->clearCookie($jwtName, '/', $cookieDomain, true, true, Cookie::SAMESITE_NONE);
        $response->headers->clearCookie($refreshName, '/', $cookieDomain, true, true, Cookie::SAMESITE_NONE);

        // 2. Nettoyage de sécurité (Anciens cookies potentiels)
        $response->headers->clearCookie('auth_token_strict', '/', $cookieDomain, true, true, Cookie::SAMESITE_NONE);
        $response->headers->clearCookie('refresh_token_strict', '/', $cookieDomain, true, true, Cookie::SAMESITE_NONE);
        $response->headers->clearCookie('jwt', '/', $cookieDomain, true, true, Cookie::SAMESITE_NONE);
        $response->headers->clearCookie('refresh_token', '/', $cookieDomain, true, true, Cookie::SAMESITE_NONE);
        // On essaie aussi sans le domain pour nettoyer les cookies 'host-only'
        $response->headers->clearCookie('jwt', '/', null, true, true, Cookie::SAMESITE_NONE);
        $response->headers->clearCookie('refresh_token', '/', null, true, true, Cookie::SAMESITE_NONE);
        // Important: HttpOnly doit être FALSE pour XSRF-TOKEN pour correspondre à sa création
        $response->headers->clearCookie('XSRF-TOKEN', '/', $cookieDomain, true, false, Cookie::SAMESITE_NONE);



        return $response;
    }
}

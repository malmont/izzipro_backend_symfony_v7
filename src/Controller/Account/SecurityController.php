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
            $data['platform']  ?? 'web',
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

        // 1. Lecture du cookie tenant strict, puis cookie générique sans tenant
        $refreshToken = $request->cookies->get($refreshName)
            ?? $request->cookies->get('refresh_token');
        $isWebSession = (bool) $refreshToken;

        // 2. Fallback payload JSON si le front envoie le token dans le body
        if (!$refreshToken) {
            $data = json_decode($request->getContent(), true) ?? [];
            $refreshToken = $data['refresh_token'] ?? $data['refreshToken'] ?? null;
        }

        if (!$refreshToken) {
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

        if ($isWebSession) {
            // Session web : l'activité repousse la déconnexion automatique (fenêtre d'inactivité glissante)
            $validRefreshToken->setValid($this->tokenService->webSessionExpiry());
            $refreshTokenManager->save($validRefreshToken);

            $response = $this->json(['message' => 'Token refreshed successfully']);
            $csrfTokenValue = $this->tokenService->attachWebSessionCookies(
                $response,
                $this->tokenService->createWebAccessToken($user),
                $refreshToken
            );
            $response->setContent(json_encode([
                'message'    => 'Token refreshed successfully',
                'csrf_token' => $csrfTokenValue,
            ]));

            return $response;
        }

        $newToken = $JWTManager->create($user);

        $response = $this->json([
            'message' => 'Token refreshed successfully'
        ], Response::HTTP_OK);

        // Cookie Host-Only (domain=null) : indispensable en multi-domaine et derrière reverse-proxy
        $cookieDomain = null;

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
        $csrfCookieName = 'XSRF-TOKEN_' . $tenantCode;
        $response->headers->setCookie(
            Cookie::create($csrfCookieName)
                ->withValue($csrfTokenValue)
                ->withHttpOnly(false)
                ->withSecure(true)
                ->withSameSite(Cookie::SAMESITE_NONE)
                ->withExpires(time() + 3600)
                ->withDomain($cookieDomain)
        );

        // Include the new CSRF token in the response body for cross-origin frontends
        $response->setContent(json_encode([
            'message'    => 'Token refreshed successfully',
            'csrf_token' => $csrfTokenValue,
        ]));

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
    public function logoutWeb(Request $request, RefreshTokenManagerInterface $refreshTokenManager): Response
    {
        $response = $this->json([
            'message' => 'Successfully logged out',
        ]);

        $tenantCode = $this->tenantConnProvider->getTenantCode() ?? 'default';
        $refreshName = 'refresh_token_' . $tenantCode;

        // 1. Révocation côté serveur : le jeton de rafraîchissement ne peut plus reconnecter personne,
        //    même si un cookie survivait dans le navigateur
        $refreshValue = $request->cookies->get($refreshName) ?? $request->cookies->get('refresh_token');
        if (!$refreshValue) {
            $data = json_decode($request->getContent(), true) ?? [];
            $refreshValue = $data['refresh_token'] ?? $data['refreshToken'] ?? null;
        }
        if (is_string($refreshValue) && $refreshValue !== '') {
            $stored = $refreshTokenManager->get($refreshValue);
            if ($stored) {
                $refreshTokenManager->delete($stored);
            }
        }

        // 2. Suppression des cookies sous leurs deux formes : sans domaine (« host-only », forme actuelle)
        //    et avec le domaine explicite (forme utilisée avant le 27/09/2026, encore présente dans les navigateurs).
        //    Pour un navigateur ce sont deux cookies distincts : effacer l'un ne supprime pas l'autre.
        $host = $request->getHost();
        $domains = [null];
        if ($host !== 'localhost' && !str_ends_with($host, '.localhost')) {
            $domains[] = $host;
        }

        $httpOnlyCookies = ['auth_token_' . $tenantCode, $refreshName, 'auth_token_strict', 'refresh_token_strict', 'jwt', 'refresh_token'];
        $readableCookies = ['XSRF-TOKEN_' . $tenantCode, 'XSRF-TOKEN'];
        foreach ($domains as $domain) {
            foreach ($httpOnlyCookies as $name) {
                $response->headers->clearCookie($name, '/', $domain, true, true, Cookie::SAMESITE_NONE);
            }
            foreach ($readableCookies as $name) {
                $response->headers->clearCookie($name, '/', $domain, true, false, Cookie::SAMESITE_NONE);
            }
        }

        return $response;
    }
}

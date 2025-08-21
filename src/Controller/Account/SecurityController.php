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
use App\Services\TenantEntityManagerProvider; // Ajout

class SecurityController extends AbstractController
{
    private TenantEntityManagerProvider $tenantEmProvider;
    private TokenService $tokenService;
    private OtpService $otpService;

    public function __construct(TenantEntityManagerProvider $tenantEmProvider, TokenService $tokenService, OtpService $otpService)
    {
        $this->tenantEmProvider = $tenantEmProvider;
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
        $refreshToken = $request->cookies->get('refresh_token');
        if (!$refreshToken) {
            return $this->json(['error' => 'Refresh token not found'], Response::HTTP_UNAUTHORIZED);
        }
        
        $validRefreshToken = $refreshTokenManager->get($refreshToken);
        if (!$validRefreshToken || !$refreshTokenManager->isValid($validRefreshToken)) {
            return $this->json(['error' => 'Invalid refresh token'], Response::HTTP_UNAUTHORIZED);
        }
        
        $user = $validRefreshToken->getUser();
        if (!$user instanceof UserInterface) {
            return $this->json(['error' => 'User not found'], Response::HTTP_UNAUTHORIZED);
        }
        
        $newToken = $JWTManager->create($user);
        
        $response = new Response();
        $response->headers->setCookie(
            Cookie::create('jwt')
                ->withValue($newToken)
                ->withHttpOnly(true)
                ->withSecure(true)
                ->withSameSite(Cookie::SAMESITE_NONE)
                ->withExpires(time() + 3600)
        );
        
        return $this->json([
            'message' => 'Token refreshed successfully'
        ], Response::HTTP_OK, [], $response->headers->all());
    }
    
    #[Route('/api/validate-token', name: 'api_validate_token', methods: ['GET'])]
    public function validateToken(): Response {
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
    public function logoutWeb(Request $request): Response {
        $domain = $request->getHost();
        $response = $this->json([
            'message' => 'Successfully logged out',
        ]);

        $response->headers->clearCookie('jwt');
        $response->headers->clearCookie('refresh_token');
        return $response;
    }
}

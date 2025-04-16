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
use Doctrine\ORM\EntityManagerInterface;
use DateTime;
use Symfony\Component\Mime\Email;
use App\Services\TokenService;
use App\Entity\OtpCode;
use App\Entity\Entreprise;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use App\Services\OtpService;

use App\Entity\User; 

class SecurityController extends AbstractController
{
    private EntityManagerInterface $entityManager;
    private TokenService $tokenService;
    private OtpService $otpService;

    public function __construct(EntityManagerInterface $entityManager, TokenService $tokenService, OtpService $otpService)
    {
        $this->entityManager = $entityManager;
        $this->tokenService  = $tokenService;
        $this->otpService    = $otpService;
    }
    
    
    #[Route(path: '/login', name: 'app_login')]
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
    public function loginApi(
        Request $request,
        JWTTokenManagerInterface $JWTManager,
    ): Response {
        // Lecture des données JSON envoyées par le client API
        $data = json_decode($request->getContent(), true) ?? [];
        $email = $data['username'] ?? '';
        $password = $data['password'] ?? '';
        $platform = $data['platform'] ?? 'mobile';
        
        // Récupération de l'utilisateur par email
        $user = $this->entityManager->getRepository(\App\Entity\User::class)->findOneBy(['email' => $email]);
        if (!$user instanceof UserInterface) {
            return $this->json(
                ['error' => 'Unauthorized'],                     // même message qu’avant
                Response::HTTP_UNAUTHORIZED
            );
        }
        

        if (!$user->isVerified()) {
            return $this->json(
                ['error' => 'Your account is not verified. Please check your email.'],
                Response::HTTP_UNAUTHORIZED
            );
            
        }
        
        if (in_array($platform, ['web', 'mobile'])) {
            if (!in_array('ROLE_USER_INTERNET', $user->getRoles(), true)) {
               return $this->json(
                    ['error' => 'This account is not allowed to access the web/mobile platform.'],
                    Response::HTTP_FORBIDDEN
                );
            }
        } elseif ($platform === 'pos') {
            if (!in_array('ROLE_USER_POS', $user->getRoles(), true)) {
                return $this->json(
                    ['error' => 'This account is not allowed to access the POS platform.'],
                    Response::HTTP_FORBIDDEN
                );
            }
        }

             // Si l'OTP est activé pour cet utilisateur, on génère et envoie l'OTP via le service dédié
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
 
        // Sinon (si OTP n'est pas activé), générer les tokens via le service factorisé
        $tokens = $this->tokenService->generateTokens($user);

        // Construire la réponse avec tokens (cookies gérés pour 'web' via le service)
        return $this->tokenService->createResponseWithTokens($tokens, $platform);

    }
    
    #[Route('/api/token/refresh', name: 'api_token_refresh', methods: ['POST'])]
    public function refreshToken(
        Request $request,
        JWTTokenManagerInterface $JWTManager,
        // Vous pouvez injecter RefreshTokenManagerInterface ici si besoin
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
        // Créez d'abord la réponse JSON
        $response = $this->json([
            'message' => 'Successfully logged out',
        ]);

        // Ajoutez les headers de suppression des cookies
        $response->headers->clearCookie('jwt');
        $response->headers->clearCookie('refresh_token');
        return $response;
    }


}

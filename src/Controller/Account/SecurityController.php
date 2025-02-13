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
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;


use App\Entity\User; 

class SecurityController extends AbstractController
{
    private EntityManagerInterface $entityManager;


    public function __construct( EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;

    }
    
    #[Route(path: '/login', name: 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        // if ($this->getUser()) {
        //     return $this->redirectToRoute('target_path');
        // }

        // get the login error if there is one
        $error = $authenticationUtils->getLastAuthenticationError();
        // last username entered by the user
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
        JWTTokenManagerInterface $JWTManager
    ): Response {
        // Lecture des données JSON envoyées par le client API
        $data = json_decode($request->getContent(), true) ?? [];
        $email = $data['username'] ?? '';
        $password = $data['password'] ?? '';
        $platform = $data['platform'] ?? 'mobile';
        
        // Récupération de l'utilisateur par email
        $user = $this->entityManager->getRepository(\App\Entity\User::class)->findOneBy(['email' => $email]);
        if (!$user instanceof UserInterface) {
            return new Response('Unauthorized', Response::HTTP_UNAUTHORIZED);
        }
        

        if (!$user->isVerified()) {
            return new Response('Your account is not verified. Please check your email.', Response::HTTP_UNAUTHORIZED);
        }
        
        if (in_array($platform, ['web', 'mobile'])) {
            if (!in_array('ROLE_USER_INTERNET', $user->getRoles(), true)) {
                return new Response('This account is not allowed to access the web/mobile platform.', Response::HTTP_FORBIDDEN);
            }
        } elseif ($platform === 'pos') {
            if (!in_array('ROLE_USER_POS', $user->getRoles(), true)) {
                return new Response('This account is not allowed to access the POS platform.', Response::HTTP_FORBIDDEN);
            }
        }
        

        $jwt = $JWTManager->create($user);

        $refreshToken = new RefreshToken();
        $refreshToken->setRefreshToken(base64_encode(random_bytes(64)));
        $refreshToken->setUsername($user->getUserIdentifier());
        $refreshToken->setValid((new DateTime())->modify('+7 days'));
        $this->entityManager->persist($refreshToken);
        $this->entityManager->flush();
        
        $response = new Response();
        
        // Pour la plateforme web, on définit des cookies et on renvoie une réponse JSON
        if ($platform === 'web') {
            $response->headers->setCookie(
                Cookie::create('jwt')
                    ->withValue($jwt)
                    ->withHttpOnly(true)
                    ->withSecure(true)
                    ->withSameSite(Cookie::SAMESITE_NONE)
                    ->withExpires(time() + 3600)
                    ->withPath('/')
            );
            $response->headers->setCookie(
                Cookie::create('refresh_token')
                    ->withValue($refreshToken->getRefreshToken())
                    ->withHttpOnly(true)
                    ->withSecure(true)
                    ->withSameSite(Cookie::SAMESITE_NONE)
                    ->withExpires(time() + 604800)
                    ->withPath('/')
            );
            $response->setContent(json_encode([
                'token' => $jwt,
                'refresh_token' => $refreshToken->getRefreshToken(),
            ]));
            $response->headers->set('Content-Type', 'application/json');
        } else {
            // Pour d'autres plateformes, renvoyer simplement la réponse JSON
            $response->setContent(json_encode([
                'token' => $jwt,
                'refresh_token' => $refreshToken->getRefreshToken(),
            ]));
            $response->headers->set('Content-Type', 'application/json');
        }
        
        return $response;
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
            return new Response('No refresh token found', Response::HTTP_UNAUTHORIZED);
        }
        
        $validRefreshToken = $refreshTokenManager->get($refreshToken);
        if (!$validRefreshToken || !$refreshTokenManager->isValid($validRefreshToken)) {
            return new Response('Invalid or expired refresh token', Response::HTTP_UNAUTHORIZED);
        }
        
        $user = $validRefreshToken->getUser();
        if (!$user instanceof UserInterface) {
            return new Response('User not found', Response::HTTP_UNAUTHORIZED);
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
        $response->headers->clearCookie('jwt', '/', $domain, true, true, Cookie::SAMESITE_NONE);
        $response->headers->clearCookie('refresh_token', '/', $domain, true, true, Cookie::SAMESITE_NONE);

        return $response;
    }


}

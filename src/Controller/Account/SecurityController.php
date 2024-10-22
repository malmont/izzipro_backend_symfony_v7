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
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;

class SecurityController extends AbstractController
{
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
        public function loginApi(AuthenticationUtils $authenticationUtils, JWTTokenManagerInterface $JWTManager, RefreshTokenManagerInterface $refreshTokenManager, Request $request): Response
        {
          
            $user = $this->getUser();
            if (!$user instanceof UserInterface) {
                return new Response('Unauthorized', Response::HTTP_UNAUTHORIZED);
            }
            $jwt = $JWTManager->create($user);
            $refreshToken = $refreshTokenManager->createForUser($user);
            $response = new Response();
            $platform = $request->request->get('platform'); 
            if ($platform === 'web') {
               
                $response->headers->setCookie(
                    Cookie::create('jwt')
                        ->withValue($jwt)
                        ->withHttpOnly(true)
                        ->withSecure(true)
                        ->withSameSite(Cookie::SAMESITE_STRICT)
                        ->withExpires(time() + 3600)  
                );
                $response->headers->setCookie(
                    Cookie::create('refresh_token')
                        ->withValue($refreshToken->getRefreshToken())
                        ->withHttpOnly(true)
                        ->withSecure(true)
                        ->withSameSite(Cookie::SAMESITE_STRICT)
                        ->withExpires(time() + 604800) 
                );
                $response->setContent(json_encode([
                    'mail' => $user->getUserIdentifier(),
                    'roles' => $user->getRoles(),
                ]));
        
                // Définir les en-têtes de la réponse pour le JSON
                $response->headers->set('Content-Type', 'application/json');
            } else {
                return $this-> json([ 
                                'mail' =>$user->getUserIdentifier(),
                                'roles'=> $user->getRoles()
                            ]);
            }

            return $response;
        }


        #[Route('/api/token/refresh', name: 'api_token_refresh', methods: ['POST'])]
        public function refreshToken(Request $request, RefreshTokenManagerInterface $refreshTokenManager, JWTTokenManagerInterface $JWTManager): Response
        {
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
                    ->withSameSite(Cookie::SAMESITE_STRICT)
                    ->withExpires(time() + 3600)
            );
        
            return $this->json([
                'message' => 'Token refreshed successfully'
            ], Response::HTTP_OK, [], $response->headers->all());
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
            $response = new Response();
            $response->headers->clearCookie('jwt', '/', null, true, true, Cookie::SAMESITE_NONE);
            $response->headers->clearCookie('refresh_token', '/', null, true, true, Cookie::SAMESITE_NONE);

            return $this->json([
                'message' => 'Successfully logged out',
            ]);
        }
    

}

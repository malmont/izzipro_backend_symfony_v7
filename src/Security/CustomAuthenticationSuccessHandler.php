<?php

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Gesdinet\JWTRefreshTokenBundle\Entity\RefreshToken;
use Doctrine\ORM\EntityManagerInterface;
use DateTime;

class CustomAuthenticationSuccessHandler implements AuthenticationSuccessHandlerInterface
{
    private JWTTokenManagerInterface $jwtManager;
    private EntityManagerInterface $entityManager;

    public function __construct(JWTTokenManagerInterface $jwtManager, EntityManagerInterface $entityManager)
    {
        $this->jwtManager = $jwtManager;
        $this->entityManager = $entityManager;
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token): Response
        {
            $user = $token->getUser();
            $jwt = $this->jwtManager->create($user);

            $response = new JsonResponse();

            $content = $request->getContent();
            $platform = 'mobile'; 

            if (!empty($content)) {
                $data = json_decode($content, true);
                $platform = $data['platform'] ?? 'mobile';
            }

            if ($platform === 'web') {
                $refreshToken = new RefreshToken();
                $refreshToken->setRefreshToken(base64_encode(random_bytes(64)));
                $refreshToken->setUsername($user->getUserIdentifier());
                $refreshToken->setValid((new DateTime())->modify('+7 days')); // Refresh token valable 7 jours

                $this->entityManager->persist($refreshToken);
                $this->entityManager->flush();

                // Ajouter le cookie JWT
                $response->headers->setCookie(
                    Cookie::create('jwt')
                        ->withValue($jwt)
                        ->withHttpOnly(true)
                        ->withSecure(true) // HTTPS uniquement
                        ->withSameSite(Cookie::SAMESITE_NONE)  // Permettre les requêtes cross-origin
                        ->withExpires(time() + 3600)  // 1 heure
                );

                // Ajouter le refresh_token
                $response->headers->setCookie(
                    Cookie::create('refresh_token')
                        ->withValue($refreshToken->getRefreshToken())
                        ->withHttpOnly(true)
                        ->withSecure(true)
                        ->withSameSite(Cookie::SAMESITE_NONE)  
                        ->withExpires(time() + 604800) // 7 jours
                );

                // Réponse avec JWT et refresh token
                $response->setData([
                    'token' => $jwt,
                    'refresh_token' => $refreshToken->getRefreshToken(),
                ]);
            } else {
                // Réponse uniquement avec le JWT pour mobile
                $response->setData([
                    'token' => $jwt,
                ]);
            }

            return $response;
        }

    
}

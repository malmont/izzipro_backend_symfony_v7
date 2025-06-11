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
use DateTime;
use App\Services\TenantEntityManagerProvider; // Ajout

class CustomAuthenticationSuccessHandler implements AuthenticationSuccessHandlerInterface
{
    private JWTTokenManagerInterface $jwtManager;
    private TenantEntityManagerProvider $tenantEmProvider;

    public function __construct(JWTTokenManagerInterface $jwtManager, TenantEntityManagerProvider $tenantEmProvider)
    {
        $this->jwtManager = $jwtManager;
        $this->tenantEmProvider = $tenantEmProvider;
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

        // Utilisation du provider multi-tenant pour l'EntityManager
        $em = $this->tenantEmProvider->getEntityManager();

        if ($platform === 'web') {
            $refreshToken = new RefreshToken();
            $refreshToken->setRefreshToken(base64_encode(random_bytes(64)));
            $refreshToken->setUsername($user->getUserIdentifier());
            $refreshToken->setValid((new DateTime())->modify('+7 days')); 

            $em->persist($refreshToken);
            $em->flush();

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
            $response->setData([
                'token' => $jwt,
                'refresh_token' => $refreshToken->getRefreshToken(),
            ]);
        } else {
            $response->setData([
                'token' => $jwt,
            ]);
        }

        return $response;
    }
}

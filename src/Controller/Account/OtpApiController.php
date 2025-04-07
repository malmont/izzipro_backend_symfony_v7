<?php
// src/Controller/Account/OtpApiController.php

namespace App\Controller\Account;

use App\Entity\User;
use App\Entity\OtpCode;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Gesdinet\JWTRefreshTokenBundle\Entity\RefreshToken;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\User\UserInterface;

class OtpApiController extends AbstractController
{
    private EntityManagerInterface $entityManager;
    
    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }
    
    #[Route(path: '/api/otp-verify', name: 'api_otp_verify', methods: ['POST'])]
    public function otpVerifyApi(
        Request $request,
        JWTTokenManagerInterface $JWTManager
    ): Response {
        $data = json_decode($request->getContent(), true) ?? [];
        $email = $data['username'] ?? '';
        $otpProvided = $data['otp'] ?? '';
        $platform = $data['platform'] ?? 'mobile';
        
        // Récupération de l'utilisateur par email
        $user = $this->entityManager->getRepository(User::class)
            ->findOneBy(['email' => $email]);
        if (!$user instanceof UserInterface) {
            return new Response('Unauthorized', Response::HTTP_UNAUTHORIZED);
        }
        
        // Recherche du code OTP correspondant à l'utilisateur et fourni
        $otpCode = $this->entityManager->getRepository(OtpCode::class)->findOneBy([
            'userOtp' => $user,
            'code' => $otpProvided
        ]);
        
        // Vérifier que le code existe et n'est pas expiré
        if (!$otpCode || $otpCode->getExpiration() < new DateTime()) {
            return new Response('Code OTP invalide ou expiré', Response::HTTP_UNAUTHORIZED);
        }
        
        // Supprimer l'OTP validé pour éviter toute réutilisation
        $this->entityManager->remove($otpCode);
        $this->entityManager->flush();
        
        // Génération des tokens
        $jwt = $JWTManager->create($user);
        $refreshToken = new RefreshToken();
        $refreshToken->setRefreshToken(base64_encode(random_bytes(64)));
        $refreshToken->setUsername($user->getUserIdentifier());
        $refreshToken->setValid((new DateTime())->modify('+7 days'));
        $this->entityManager->persist($refreshToken);
        $this->entityManager->flush();
        
        $response = new Response();
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
            $response->setContent(json_encode([
                'token' => $jwt,
                'refresh_token' => $refreshToken->getRefreshToken(),
            ]));
            $response->headers->set('Content-Type', 'application/json');
        }
        
        return $response;
    }
}

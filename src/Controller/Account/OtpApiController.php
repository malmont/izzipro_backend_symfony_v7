<?php
// src/Controller/Account/OtpApiController.php

namespace App\Controller\Account;

use App\Entity\User;
use App\Entity\OtpCode;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use App\Services\TokenService;
use Symfony\Component\HttpFoundation\RequestStack;

class OtpApiController extends AbstractController
{
    private EntityManagerInterface $entityManager;
    private TokenService $tokenService;
    private $session;

    public function __construct(EntityManagerInterface $entityManager, TokenService $tokenService, RequestStack $requestStack)
    {
        $this->entityManager = $entityManager;
        $this->tokenService  = $tokenService;
        $this->session       = $requestStack->getSession();
    }
    
    #[Route(path: '/api/otp-verify', name: 'api_otp_verify', methods: ['POST'])]
    public function otpVerifyApi(Request $request): Response
    {
        // Lecture des données JSON envoyées par le client API
        $data = json_decode($request->getContent(), true) ?? [];
        $email = $data['username'] ?? '';
        $otpProvided = $data['otp'] ?? '';
        $platform = $data['platform'] ?? 'mobile';
        
        // Récupération de l'utilisateur par email
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        if (!$user instanceof UserInterface) {
            return new Response('Unauthorized', Response::HTTP_UNAUTHORIZED);
        }
        
        $otpCode = $this->entityManager
            ->getRepository(OtpCode::class)
            ->findOneBy([
                'userOtp' => $user,
                'code'    => $otpProvided
            ]);
        
        // Vérifier que le code OTP existe et n'est pas expiré
        if (!$otpCode || $otpCode->getExpiration() < new DateTime()) {
            return new Response('Code OTP invalide ou expiré', Response::HTTP_UNAUTHORIZED);
        }
        
        // Supprimer l'OTP validé pour éviter toute réutilisation
        $this->entityManager->remove($otpCode);
        $this->entityManager->flush();
        
        // Génération des tokens via le service factorisé
        $tokens = $this->tokenService->generateTokens($user);
        
        // Création de la réponse HTTP avec les tokens (les cookies sont ajoutés si la plateforme est 'web')
        return $this->tokenService->createResponseWithTokens($tokens, $platform);
    }
}

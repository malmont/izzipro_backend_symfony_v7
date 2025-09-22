<?php
// src/Controller/Account/OtpApiController.php

namespace App\Controller\Account;

use App\Entity\User;
use App\Entity\OtpCode;
use DateTime;
use App\Services\TenantEntityManagerProvider;
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
    private TenantEntityManagerProvider $emProvider;
    private TokenService $tokenService;
    private $session;

    public function __construct(
        TenantEntityManagerProvider $emProvider,
        TokenService $tokenService,
        RequestStack $requestStack
    ) {
        $this->emProvider   = $emProvider;
        $this->tokenService = $tokenService;
        $this->session      = $requestStack->getSession();
    }

    #[Route(path: '/api/otp-verify', name: 'api_otp_verify', methods: ['POST'])]
    public function otpVerifyApi(Request $request): Response
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $email = $data['username'] ?? '';
        $otpProvided = $data['otp'] ?? '';
        $platform = $data['platform'] ?? 'mobile';
        $em = $this->emProvider->getEntityManager();
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        if (!$user instanceof UserInterface) {
            return $this->json([
                'error' => 'Unauthorized'
            ], Response::HTTP_UNAUTHORIZED);
        }

        $otpCode = $em->getRepository(OtpCode::class)->findOneBy([
            'userOtp' => $user,
            'code'    => $otpProvided
        ]);

        if (!$otpCode || $otpCode->getExpiration() < new DateTime()) {
            return $this->json([
                'error' => 'Code OTP invalide ou expiré'
            ], Response::HTTP_UNAUTHORIZED);
        }

        $em->remove($otpCode);
        $em->flush();

        $tokens = $this->tokenService->generateTokens($user);

        return $this->tokenService->createResponseWithTokens($tokens, $platform);
    }
}

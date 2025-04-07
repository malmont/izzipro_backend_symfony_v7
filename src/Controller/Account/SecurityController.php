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

use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use App\Entity\OtpCode;
use App\Entity\Entreprise;
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
        MailerInterface $mailer
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

           // Si l'OTP est activé pour cet utilisateur
           if ($user->isOtpEnabled()) {
            // Générer un OTP à 6 chiffres
            $otp = random_int(100000, 999999);
            
            // Créer et sauvegarder l'entité OtpCode
            $otpCode = new OtpCode();
            $otpCode->setUserOtp($user);
            $otpCode->setCode((string)$otp);
            // Le code est valable 5 minutes
            $otpCode->setExpiration((new DateTime())->modify('+5 minutes'));
            $this->entityManager->persist($otpCode);
            $this->entityManager->flush();
            // Envoyer l'OTP par email
            // Récupérer la configuration d'email depuis la base de données
            $emailConfig = $this->entityManager->getRepository(\App\Entity\EmailConfiguration::class)->findOneBy([]);

            // Définir les valeurs d'expéditeur en fonction de la configuration ou des valeurs par défaut
            if (!$emailConfig) {
                $fromEmail = 'no-reply@votredomaine.com';
                $fromName  = 'Votre Société';
            } else {
                $fromEmail = $emailConfig->getFromEmail();
                $fromName  = $emailConfig->getFromName();
            }

            // Récupérer les informations de l'entreprise (on suppose qu'il n'y a qu'une seule entreprise)
            $entreprise = $this->entityManager
                ->getRepository(\App\Entity\Entreprise::class)
                ->findOneBy([]);

            // Création et envoi du message OTP avec le template Twig enrichi
            $domain = $request->getSchemeAndHttpHost() . '/assets/uploads/email-logos/';
            $emailMessage = (new Email())
                ->from(sprintf('%s <%s>', $fromName, $fromEmail))
                ->to($user->getEmail())
                ->subject('Votre code OTP')
                ->html(
                    $this->renderView('security/2fa_email.html.twig', [
                        'code'       => $otp,
                        'lifetime'   => 300, // durée en secondes
                        'entreprise' => $entreprise,
                        'domain'     => $domain,
                    ])
                );
            $mailer->send($emailMessage);

            
            // Réponse indiquant que la vérification OTP est requise
            return new Response(
                json_encode([
                    'otp_required' => true,
                    'message' => 'Un code OTP vous a été envoyé par email. Veuillez le saisir pour continuer.'
                ]),
                Response::HTTP_OK,
                ['Content-Type' => 'application/json']
            );
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
        $response->headers->clearCookie('jwt');
        $response->headers->clearCookie('refresh_token');
        return $response;
    }


}

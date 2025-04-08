<?php
// src/Security/LoginAuthenticator.php

namespace App\Security;

use App\Entity\OtpCode;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Util\TargetPathTrait;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Twig\Environment;
use App\Services\OtpService;

class LoginAuthenticator extends AbstractLoginFormAuthenticator
{
    use TargetPathTrait;

    public const LOGIN_ROUTE = 'app_login';

    private EntityManagerInterface $entityManager;
    private MailerInterface $mailer;
    private $session;
    private Environment $twig;
    private OtpService $otpService;

    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        EntityManagerInterface $entityManager,
        MailerInterface $mailer,
        RequestStack $requestStack,
        Environment $twig,
        OtpService $otpService
    ) {
        $this->entityManager = $entityManager;
        $this->mailer        = $mailer;
        $this->session       = $requestStack->getSession();
        $this->twig          = $twig;
        $this->otpService    = $otpService;
    }

    public function authenticate(Request $request): Passport
    {
        $email = $request->request->get('email', '');
        $request->getSession()->set(Security::LAST_USERNAME, $email);

        return new Passport(
            new UserBadge($email),
            new PasswordCredentials($request->request->get('password', '')),
            [
                new CsrfTokenBadge('authenticate', $request->request->get('_csrf_token')),
            ]
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $user = $token->getUser();

        // Si l'utilisateur a activé l'OTP et qu'il n'est pas encore validé en session
        if ($user instanceof \App\Entity\User && $user->isOtpEnabled() && !$this->session->get('otp_validated')) {
            // Appel au service OTP qui se charge de générer le code, de créer l'entité et d'envoyer l'e-mail
            $this->otpService->generateAndSendOtp($user, $request);

            // Stocker l'identifiant de l'utilisateur en attente de validation OTP
            $this->session->set('pending_otp_user', $user->getId());
            $this->session->remove('otp_validated');

            // Rediriger vers la page de vérification OTP (par exemple 'account_otp')
            return new RedirectResponse($this->urlGenerator->generate('account_otp'));
        }

        if ($targetPath = $this->getTargetPath($request->getSession(), $firewallName)) {
            return new RedirectResponse($targetPath);
        }

        return new RedirectResponse($this->urlGenerator->generate('app_account'));
    }

    protected function getLoginUrl(Request $request): string
    {
        return $this->urlGenerator->generate(self::LOGIN_ROUTE);
    }
}

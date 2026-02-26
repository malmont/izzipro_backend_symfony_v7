<?php
// src/Security/LoginAuthenticator.php

namespace App\Security;

use App\Entity\User;
use App\Services\OtpService;
use App\Services\TenantEntityManagerProvider;
use DateTime;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Util\TargetPathTrait;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Mailer\MailerInterface;
use Twig\Environment;

class LoginAuthenticator extends AbstractLoginFormAuthenticator
{
    use TargetPathTrait;

    public const LOGIN_ROUTE = 'app_login';

    private UrlGeneratorInterface $urlGenerator;
    private TenantEntityManagerProvider $tenantEmProvider;
    private RequestStack $requestStack;
    private OtpService $otpService;

    public function __construct(
        UrlGeneratorInterface $urlGenerator,
        TenantEntityManagerProvider $tenantEmProvider,
        RequestStack $requestStack,
        OtpService $otpService
    ) {
        $this->urlGenerator     = $urlGenerator;
        $this->tenantEmProvider = $tenantEmProvider;
        $this->requestStack     = $requestStack;
        $this->otpService       = $otpService;
    }

    public function authenticate(Request $request): Passport
    {
        $email = $request->request->get('email', '');
        $request->getSession()->set(Security::LAST_USERNAME, $email);

        return new Passport(
            new UserBadge($email, function (string $userIdentifier) {
                // Si besoin de multi-tenant ici aussi, on peut initialiser
                $em = $this->tenantEmProvider->getEntityManager();
                $user = $em->getRepository(User::class)->findOneBy(['email' => $userIdentifier]);
                if (!$user) {
                    throw new CustomUserMessageAuthenticationException('Utilisateur introuvable.');
                }
                return $user;
            }),
            new PasswordCredentials($request->request->get('password', '')),
            [
                new CsrfTokenBadge('authenticate', $request->request->get('_csrf_token')),
            ]
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        // On récupère la session depuis la requête, désormais disponible
        $session = $request->getSession();

        /** @var User $user */
        $user = $token->getUser();

        // 1) Si OTP activé et non validé en session -> générer + rediriger vers le formulaire OTP
        if ($user->isOtpEnabled() && !$session->get('otp_validated')) {
            $this->otpService->generateAndSendOtp($user, $request);
            $session->set('pending_otp_user', $user->getId());
            $session->remove('otp_validated');

            return new RedirectResponse(
                $this->urlGenerator->generate('account_otp')
            );
        }

        // 2) Sinon, redirection vers la page demandée ou la page par défaut
        if ($targetPath = $this->getTargetPath($session, $firewallName)) {
            return new RedirectResponse($targetPath);
        }

        return new RedirectResponse(
            $this->urlGenerator->generate('app_account')
        );
    }

    protected function getLoginUrl(Request $request): string
    {
        return $this->urlGenerator->generate(self::LOGIN_ROUTE);
    }
}

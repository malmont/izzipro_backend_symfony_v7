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
use Symfony\Component\Security\Http\SecurityRequestAttributes;
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
        $email = trim((string) $request->request->get('email', ''));
        $password = (string) $request->request->get('password', '');

        // Sécurité renforcée : validation d'entrée anti-XSS et anti-DoS
        if (strlen($email) > 180 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $email)) {
            throw new CustomUserMessageAuthenticationException('Format d\'identifiant invalide.');
        }
        if (strlen($password) > 4096) {
            throw new CustomUserMessageAuthenticationException('Longueur de mot de passe invalide.');
        }

        $request->getSession()->set(SecurityRequestAttributes::LAST_USERNAME, $email);

        return new Passport(
            new UserBadge($email, function (string $userIdentifier) {
                // Si besoin de multi-tenant ici aussi, on peut initialiser
                $em = $this->tenantEmProvider->getEntityManager();
                $user = $em->getRepository(User::class)->findOneBy(['email' => $userIdentifier]);
                if (!$user) {
                    throw new CustomUserMessageAuthenticationException('Identifiant ou mot de passe incorrect.');
                }
                return $user;
            }),
            new PasswordCredentials($password),
            [
                new CsrfTokenBadge('authenticate', (string) $request->request->get('_csrf_token')),
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

        // 2) Sinon, redirection vers la page demandée ou vers le tableau de bord admin
        if ($targetPath = $this->getTargetPath($session, $firewallName)) {
            return new RedirectResponse($targetPath);
        }

        return new RedirectResponse(
            $this->urlGenerator->generate('admin')
        );
    }

    protected function getLoginUrl(Request $request): string
    {
        return $this->urlGenerator->generate(self::LOGIN_ROUTE);
    }
}

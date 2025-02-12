<?php

namespace App\Security;

use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Util\TargetPathTrait;
use App\Entity\User;

class ApiLoginAuthenticator extends AbstractLoginFormAuthenticator
{
    use TargetPathTrait;

    public const LOGIN_ROUTE = 'api_login';

    private ManagerRegistry $doctrine;
    private CustomAuthenticationSuccessHandler $successHandler;

    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        ManagerRegistry $doctrine,
        CustomAuthenticationSuccessHandler $successHandler
    ) {
        $this->doctrine = $doctrine;
        $this->successHandler = $successHandler;
    }

    public function authenticate(Request $request): Passport
    {
        // Lecture des données JSON (API stateless)
        $data = json_decode($request->getContent(), true) ?? [];
        $email = $data['username'] ?? '';
        $password = $data['password'] ?? '';
        $platform = $data['platform'] ?? 'mobile';
        return new Passport(
            new UserBadge($email, function (string $userIdentifier) use ($platform) {
                $em = $this->doctrine->getManager();
                $user = $em->getRepository(User::class)->findOneBy(['email' => $userIdentifier]);
                if (!$user) {
                    throw new CustomUserMessageAuthenticationException('User not found.');
                }
                if (!$user->isVerified()) {
                    throw new CustomUserMessageAuthenticationException('Your account is not verified. Please check your email.');
                }
                // Vérifier le rôle en fonction de la plateforme
                if (in_array($platform, ['web', 'mobile'])) {
                    if (!in_array('ROLE_USER_INTERNET', $user->getRoles(), true)) {
                        throw new CustomUserMessageAuthenticationException('This account is not allowed to access the web/mobile platform.');
                    }
                } elseif ($platform === 'pos') {
                    if (!in_array('ROLE_USER_POS', $user->getRoles(), true)) {
                        throw new CustomUserMessageAuthenticationException('This account is not allowed to access the POS platform.');
                    }
                }
                return $user;
            }),
            new PasswordCredentials($password)
            // Note : Aucun CsrfTokenBadge ni utilisation de session dans une API stateless
        );
    }

    public function onAuthenticationSuccess(Request $request, \Symfony\Component\Security\Core\Authentication\Token\TokenInterface $token, string $firewallName): ?Response
    {
        // Délégation du traitement du succès d'authentification au CustomAuthenticationSuccessHandler
        return $this->successHandler->onAuthenticationSuccess($request, $token);
    }

    protected function getLoginUrl(Request $request): string
    {
        return $this->urlGenerator->generate(self::LOGIN_ROUTE);
    }
}

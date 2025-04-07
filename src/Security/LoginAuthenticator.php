<?php

namespace App\Security;

use App\Entity\OtpCode;
use App\Entity\EmailConfiguration;
use App\Entity\Entreprise;
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

class LoginAuthenticator extends AbstractLoginFormAuthenticator
{
    use TargetPathTrait;

    public const LOGIN_ROUTE = 'app_login';

    private EntityManagerInterface $entityManager;
    private MailerInterface $mailer;
    private $session;
    private Environment $twig;

    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        EntityManagerInterface $entityManager,
        MailerInterface $mailer,
        RequestStack $requestStack,
        Environment $twig
    ) {
        $this->entityManager = $entityManager;
        $this->mailer        = $mailer;
        $this->session       = $requestStack->getSession();
        $this->twig          = $twig;
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
            // Générer un OTP à 6 chiffres
            $otp = random_int(100000, 999999);

            // Créer et persister l'entité OtpCode
            $otpCode = new OtpCode();
            $otpCode->setUserOtp($user);
            $otpCode->setCode((string)$otp);
            $otpCode->setExpiration((new DateTime())->modify('+5 minutes'));
            $this->entityManager->persist($otpCode);
            $this->entityManager->flush();

            // Récupérer la configuration d'email
            $emailConfig = $this->entityManager
                ->getRepository(EmailConfiguration::class)
                ->findOneBy([]);
            if (!$emailConfig) {
                $fromEmail = 'no-reply@votredomaine.com';
                $fromName  = 'Votre Société';
            } else {
                $fromEmail = $emailConfig->getFromEmail();
                $fromName  = $emailConfig->getFromName();
            }
            
            // Récupérer l'entreprise (on suppose qu'il n'y a qu'une seule entreprise)
            $entreprise = $this->entityManager
                ->getRepository(Entreprise::class)
                ->findOneBy([]);
            
            // Construire le domaine pour le logo (ex: https://backend-strapi.online/assets/uploads/email-logos/)
            $domain = $request->getSchemeAndHttpHost() . '/assets/uploads/email-logos/';
            
            // Envoyer l'email OTP avec le template Twig enrichi
            $emailMessage = (new Email())
                ->from(sprintf('%s <%s>', $fromName, $fromEmail))
                ->to($user->getEmail())
                ->subject('Votre code OTP')
                ->html(
                    $this->twig->render('security/2fa_email.html.twig', [
                        'code'       => $otp,
                        'lifetime'   => 300,
                        'entreprise' => $entreprise,
                        'domain'     => $domain,
                    ])
                );
            $this->mailer->send($emailMessage);

            // Stocker l'identifiant de l'utilisateur en attente de validation OTP
            $this->session->set('pending_otp_user', $user->getId());
            $this->session->remove('otp_validated');

            // Rediriger vers la page de vérification OTP (par exemple 'admin_otp' ou 'account_otp')
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

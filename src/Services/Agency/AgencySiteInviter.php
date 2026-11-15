<?php

namespace App\Services\Agency;

use App\Entity\User;
use App\Services\EmailConfigurationService\EmailLocale;
use App\Services\EmailConfigurationService\EmailSenderService;
use App\Services\Site\AdminSpaceUrl;
use App\Services\Site\SiteName;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use App\Services\TenantPublicHostResolver;
use Psr\Log\LoggerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * L'administrateur d'un site créé par une agence (lot AG-2, 15/11/2026, `docs/agences-et-import.md` § 8). La personne désignée par
 * l'agence reçoit un courriel pour choisir son mot de passe : un lien à usage unique, valable `INVITATION_DAYS` jours (le mécanisme
 * du mot de passe oublié : `resetToken`, `POST /api/password-reset/verify` puis `/confirm`). Son compte, `ROLE_ADMIN` du site neuf,
 * ne sert à rien tant que le mot de passe n'est pas choisi (mot de passe aléatoire, compte non vérifié). Le jeton n'est jamais rendu
 * à l'agence. Un compte qui existe déjà à cette adresse n'est JAMAIS transformé : c'est signalé (`ACCOUNT_EXISTS`).
 *
 * Tourne dans un worker, le site neuf étant le site courant (`switchTenant`) : jamais dans la requête de l'agence.
 */
class AgencySiteInviter
{
    public const INVITATION_DAYS = 7;
    public const ROLE = 'ROLE_ADMIN';
    private const TEMPLATE = 'emails/support_agent_invitation.html.twig'; // même forme : un message, un bouton, la durée du lien

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly EmailSenderService $mailer,
        private readonly EmailLocale $locale,
        private readonly TranslatorInterface $translator,
        private readonly AdminSpaceUrl $adminSpace,
        private readonly TenantConnectionManager $tenants,
        private readonly TenantPublicHostResolver $hosts,
        private readonly SiteName $siteName,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Crée le compte de l'administrateur désigné et lui envoie son lien.
     *
     * @return array{sent: bool, reason: ?string, expiresAt: ?string} reason : ACCOUNT_EXISTS | NO_LINK | EMAIL_FAILED | null
     */
    public function invite(string $email, string $firstName, string $lastName, ?string $locale, string $agencyName): array
    {
        $em = $this->emProvider->getEntityManager();
        if ($em->getRepository(User::class)->findOneBy(['email' => $email]) !== null) {
            return ['sent' => false, 'reason' => 'ACCOUNT_EXISTS', 'expiresAt' => null];
        }
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname($firstName)->setLastname($lastName)
            ->setRoles([self::ROLE])->setIsVerified(false)->setSignupChannel(User::SIGNUP_ADMIN_INVITE);
        $user->setPassword($this->hasher->hashPassword($user, bin2hex(random_bytes(24)))); // inutilisable : la personne choisit le sien par le lien
        $em->persist($user);
        $em->flush();

        return $this->send($user, $locale, $agencyName);
    }

    /**
     * Un nouveau lien pour l'administrateur désigné qui n'a pas encore choisi son mot de passe (l'ancien ne vaut plus).
     *
     * @return array{sent: bool, reason: ?string, expiresAt: ?string} reason : NOT_FOUND | ALREADY_ACTIVE | NO_LINK | EMAIL_FAILED | null
     */
    public function resend(string $email, ?string $locale, string $agencyName): array
    {
        $user = $this->emProvider->getEntityManager()->getRepository(User::class)->findOneBy(['email' => $email]);
        if (!$user instanceof User || $user->getSignupChannel() !== User::SIGNUP_ADMIN_INVITE) {
            return ['sent' => false, 'reason' => 'NOT_FOUND', 'expiresAt' => null];
        }
        if ($user->isVerified()) {
            return ['sent' => false, 'reason' => 'ALREADY_ACTIVE', 'expiresAt' => null];
        }

        return $this->send($user, $locale, $agencyName);
    }

    /** @return array{sent: bool, reason: ?string, expiresAt: ?string} */
    private function send(User $user, ?string $locale, string $agencyName): array
    {
        $token = bin2hex(random_bytes(32));
        $expires = new \DateTimeImmutable('+' . self::INVITATION_DAYS . ' days');
        $user->setResetToken($token)->setResetTokenExpiresAt($expires);
        $this->emProvider->getEntityManager()->flush();
        $out = ['sent' => false, 'reason' => null, 'expiresAt' => $expires->format(\DateTimeInterface::ATOM)];
        $link = $this->adminSpace->activation($token); // jeton dans le fragment de l'adresse : jamais journalisé par un serveur
        if ($link === null) {
            $this->logger->warning('Agences : invitation d\'un administrateur sans lien (aucune adresse connue pour ce site).');

            return ['reason' => 'NO_LINK'] + $out;
        }
        $language = $this->locale->language($locale);
        $code = (string) $this->tenants->getCurrentTenantCode();
        $site = $code !== '' ? $this->hosts->describe($code) : null;
        $t = fn (string $key, array $params = []) => $this->translator->trans('site_admin_invitation.' . $key, $params, 'emails', $language);
        $params = ['%site%' => $this->siteName->name(), '%agency%' => $agencyName, '%days%' => self::INVITATION_DAYS];
        $name = trim((string) $user->getFirstname());
        try {
            // envoi direct : le worker sait si le courriel est vraiment parti (« envoyé » ne veut pas dire « mis en file »)
            $sent = $this->mailer->sendTemplatedEmail((string) $user->getEmail(), $t('subject', $params), self::TEMPLATE, [
                'title' => $t('title', $params), 'greeting' => $name !== '' ? $t('greeting', ['%name%' => $name]) : $t('greeting_plain'), 'message' => $t('message', $params),
                'button' => $t('button'), 'link' => $link, 'validity' => $t('validity', $params), 'ignore' => $t('ignore'), 'siteLine' => $params['%site%'],
            ], $language, (string) ($site['host'] ?? $code), null, null, [], true);
        } catch (\Throwable $e) {
            $this->logger->error('Agences : courriel d\'invitation d\'un administrateur en échec (' . $e::class . ').');
            $sent = false;
        }

        return ['sent' => $sent, 'reason' => $sent ? null : 'EMAIL_FAILED'] + $out;
    }
}

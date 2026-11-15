<?php

namespace App\Support\Services;

use App\Entity\User;
use App\Services\ContentAuditService\ContentAuditRecorder;
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
 * Agents d'assistance d'un site (lot SUPPORT-WEB, 14/11/2026, `docs/assistance.md`) : l'administrateur du site invite un agent, relance
 * son invitation, lui retire l'accès. Décisions du propriétaire :
 * - l'invitation ne s'ouvre que si le DROIT du module Assistance est accordé au site (pas le mode « demo » de la plateforme) ;
 * - un agent invité n'a JAMAIS d'autre rôle que ROLE_SUPPORT : il n'ouvre que le module, et un compte qui existe déjà n'est pas transformé ;
 * - il choisit son mot de passe par un lien à usage unique, valable `INVITATION_HOURS` heures (le mécanisme du mot de passe oublié :
 *   `resetToken`, `POST /api/password-reset/verify` puis `POST /api/password-reset/confirm`) ; le jeton n'est jamais rendu à l'administrateur.
 * Attribution et retrait au journal du site (`user_team`, `grant` / `revoke`), comme les autres rôles d'équipe.
 */
class SupportAgentService
{
    public const ROLE = 'ROLE_SUPPORT';
    public const INVITATION_HOURS = 48;
    private const TEMPLATE = 'emails/support_agent_invitation.html.twig';

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly SupportDirectoryService $directory,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly EmailSenderService $mailer,
        private readonly EmailLocale $locale,
        private readonly TranslatorInterface $translator,
        private readonly AdminSpaceUrl $adminSpace,
        private readonly TenantConnectionManager $tenants,
        private readonly TenantPublicHostResolver $hosts,
        private readonly ContentAuditRecorder $audit,
        private readonly LoggerInterface $logger,
        private readonly SiteName $siteName,
    ) {
    }

    /**
     * POST /api/support/agents `{ email, firstName, lastName, locale? }`.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function invite(array $body, User $by): array
    {
        $this->requireModule();
        $errors = [];
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        if ($email === '') {
            $errors['email'] = 'REQUIRED';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 180) {
            $errors['email'] = 'INVALID_EMAIL';
        }
        $names = [];
        foreach (['firstName', 'lastName'] as $field) {
            $names[$field] = is_string($body[$field] ?? null) ? trim($body[$field]) : '';
            if ($names[$field] === '') {
                $errors[$field] = 'REQUIRED';
            } elseif (mb_strlen($names[$field]) > 100) {
                $errors[$field] = 'TOO_LONG';
            }
        }
        $locale = $body['locale'] ?? null;
        if ($locale !== null && !in_array($locale, [EmailLocale::FRENCH, EmailLocale::ENGLISH], true)) {
            $errors['locale'] = 'INVALID_LOCALE';
        }
        if ($errors !== []) {
            throw SupportException::validation($errors);
        }
        $em = $this->emProvider->getEntityManager();
        if ($em->getRepository(User::class)->findOneBy(['email' => $email]) !== null) {
            // un compte existant n'est jamais transformé en agent : l'agent d'assistance est un compte à part, sans autre rôle
            throw new SupportException('ACCOUNT_EXISTS', 'Un compte existe déjà avec cette adresse : un agent d\'assistance est un compte à part.', 409);
        }
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname($names['firstName'])->setLastname($names['lastName'])
            ->setRoles([self::ROLE])->setIsVerified(false)->setSignupChannel(User::SIGNUP_SUPPORT_INVITE);
        $user->setPassword($this->hasher->hashPassword($user, bin2hex(random_bytes(24)))); // inutilisable : l'agent choisit le sien par le lien
        $em->persist($user);
        $em->flush();
        $this->audit->record('user_team', (int) $user->getId(), 'grant', null, ['role' => self::ROLE, 'marks' => ['invite']], ['role', 'marks']);

        return $this->send($user, $by, is_string($locale) ? $locale : null);
    }

    /** POST /api/support/agents/{id}/invitation : un nouveau lien pour un agent qui n'a pas encore choisi son mot de passe (l'ancien ne vaut plus) */
    public function resend(int $id, User $by, ?string $locale = null): array
    {
        $this->requireModule();
        $user = $this->agent($id);
        if ($user->isVerified()) {
            throw new SupportException('AGENT_ALREADY_ACTIVE', 'Cet agent a déjà choisi son mot de passe.', 409);
        }

        return $this->send($user, $by, $locale);
    }

    /**
     * DELETE /api/support/agents/{id} : l'agent perd l'accès. Son compte reste (les messages qu'il a écrits gardent leur auteur), sans
     * rôle d'accès ; ses sessions sont closes, son invitation ne vaut plus, les demandes qui lui étaient attribuées redeviennent sans agent.
     *
     * @return array{id: int, unassigned: int}
     */
    public function revoke(int $id): array
    {
        $user = $this->agent($id);
        $em = $this->emProvider->getEntityManager();
        // lot AG-1 : un rôle d'agence n'est ni donné ni retiré par un administrateur de site : il reste
        $user->setRoles(array_values(array_intersect($user->getRoles(), \App\Security\AgencyRoles::ALL)))->setResetToken(null)->setResetTokenExpiresAt(null);
        $em->flush();
        $conn = $em->getConnection();
        $conn->executeStatement('DELETE FROM refresh_tokens WHERE username = ?', [(string) $user->getUserIdentifier()]);
        $unassigned = (int) $conn->executeStatement("UPDATE support_ticket SET assigned_user_id = NULL WHERE assigned_user_id = ? AND status <> 'closed'", [$id]);
        $this->audit->record('user_team', $id, 'revoke', ['role' => self::ROLE], ['unassigned' => $unassigned], ['role', 'unassigned']);

        return ['id' => $id, 'unassigned' => $unassigned];
    }

    /**
     * Invitations en attente (agents qui n'ont pas encore choisi leur mot de passe), pour l'administrateur.
     *
     * @return list<array{id: int, name: string, expiresAt: ?string, expired: bool}>
     */
    public function pending(): array
    {
        $rows = $this->emProvider->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT id, firstname, lastname, reset_token_expires_at FROM "user" WHERE deleted_at IS NULL AND is_verified = false AND signup_channel = ?'
            . " AND roles::text LIKE '%\"ROLE_SUPPORT\"%' ORDER BY id", [User::SIGNUP_SUPPORT_INVITE]);
        $now = new \DateTimeImmutable();
        $out = [];
        foreach ($rows as $r) {
            $expires = $r['reset_token_expires_at'] !== null ? new \DateTimeImmutable((string) $r['reset_token_expires_at']) : null;
            $name = trim(($r['firstname'] ?? '') . ' ' . ($r['lastname'] ?? ''));
            $out[] = ['id' => (int) $r['id'], 'name' => $name !== '' ? $name : sprintf('Compte n° %d', (int) $r['id']),
                'expiresAt' => $expires?->format(\DateTimeInterface::ATOM), 'expired' => $expires === null || $expires < $now];
        }

        return $out;
    }

    /** Pose un jeton neuf (l'ancien ne vaut plus), envoie le courriel d'invitation et rend l'état de l'invitation, sans le jeton */
    private function send(User $user, User $by, ?string $locale): array
    {
        $em = $this->emProvider->getEntityManager();
        $token = bin2hex(random_bytes(32));
        $expires = new \DateTimeImmutable('+' . self::INVITATION_HOURS . ' hours');
        $user->setResetToken($token)->setResetTokenExpiresAt($expires);
        $em->flush();
        $language = $this->locale->language($locale);
        $link = $this->adminSpace->activation($token); // jeton dans le fragment de l'adresse : jamais journalisé par un serveur
        $code = (string) $this->tenants->getCurrentTenantCode();
        $site = $code !== '' ? $this->hosts->describe($code) : null;
        $t = fn (string $key, array $params = []) => $this->translator->trans('support_agent_invitation.' . $key, $params, 'emails', $language);
        $params = ['%site%' => $this->siteName->name(), '%inviter%' => SupportDirectoryService::name($by), '%hours%' => self::INVITATION_HOURS];
        $sent = false;
        if ($link !== null) {
            try {
                $sent = $this->mailer->sendTemplatedEmail((string) $user->getEmail(), $t('subject', $params), self::TEMPLATE, [
                    'title' => $t('title', $params), 'greeting' => $t('greeting', ['%name%' => $user->getFirstname()]), 'message' => $t('message', $params),
                    'button' => $t('button'), 'link' => $link, 'validity' => $t('validity', $params), 'ignore' => $t('ignore'), 'siteLine' => $params['%site%'],
                ], $language, (string) ($site['host'] ?? $code));
            } catch (\Throwable $e) {
                $this->logger->error('Assistance : courriel d\'invitation d\'un agent en échec (' . $e::class . ').');
            }
        } else {
            $this->logger->warning('Assistance : invitation d\'un agent sans lien (aucune adresse d\'espace d\'administration connue pour ce site).');
        }

        return ['agent' => ['id' => (int) $user->getId(), 'name' => SupportDirectoryService::name($user), 'email' => $user->getEmail(), 'role' => 'support', 'pending' => true],
            'invitation' => ['emailSent' => $sent, 'expiresAt' => $expires->format(\DateTimeInterface::ATOM), 'validHours' => self::INVITATION_HOURS]];
    }

    /** Un compte Assistance SEUL de ce site ; 404 sinon (un administrateur ou un client n'est pas un agent que cette route gère) */
    private function agent(int $id): User
    {
        $user = $this->emProvider->getEntityManager()->find(User::class, $id);
        // lot AG-1 : un rôle d'agence (donné par la plateforme) ne retire pas à un agent sa qualité d'agent
        if (!$user instanceof User || array_values(array_diff($user->getRoles(), ['ROLE_USER', ...\App\Security\AgencyRoles::ALL])) !== [self::ROLE]) {
            throw new SupportException('AGENT_NOT_FOUND', 'Agent d\'assistance introuvable.', 404);
        }

        return $user;
    }

    private function requireModule(): void
    {
        if (!$this->directory->moduleGranted()) {
            throw new SupportException('SUPPORT_MODULE_REQUIRED', 'Le module Assistance doit être accordé au site pour inviter un agent.', 403, ['feature' => SupportDirectoryService::FEATURE]);
        }
    }
}

<?php

namespace App\Abo\Services;

use App\Abo\Entity\BenefitOccurrence;
use App\Entity\Subscription;
use App\Entity\User;
use App\Security\TeamRoles;
use App\Services\ContentAuditService\ContentAuditRecorder;
use App\Services\EmailConfigurationService\EmailLocale;
use App\Services\EmailConfigurationService\EmailSenderService;
use App\Services\Site\AdminSpaceUrl;
use App\Services\Site\SiteName;
use App\Services\SubscriptionService\SubscriptionLock;
use App\Services\TeamRoleService\TeamRoleService;
use App\Services\TenantConnectionManager;
use App\Services\TenantConnectionProvider;
use App\Services\TenantEntityManagerProvider;
use App\Services\TenantPublicHostResolver;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Les intervenants des abonnements, côté administrateur du site (lot ABO-3b, 20/11/2026, `docs/boutique.md` § « Intervenant et fil de
 * l'abonné ») : la liste, l'invitation (compte existant : le rôle est posé ; personne nouvelle : un lien de 7 jours pour choisir son mot
 * de passe, comme les agents de l'Assistance), le retrait (ses dates à venir redeviennent sans intervenant), l'affectation d'une date.
 * Refus : un compte client (abonnement ou commande) ne devient pas intervenant, ni un administrateur (il a déjà tout).
 * L'intervenant d'une date : celui de la date, sinon celui du créneau, sinon celui de la prestation (`EFFECTIVE`).
 */
class AboStaffService
{
    public const ROLE = TeamRoles::STAFF_PROVIDER;
    public const INVITATION_HOURS = 168;
    /** L'intervenant d'une date : `o` = benefit_occurrence, `sl` = benefit_slot (jointure à gauche), `b` = benefit */
    public const EFFECTIVE = 'COALESCE(o.staff_user_id, sl.staff_user_id, b.default_staff_user_id)';
    private const TEMPLATE = 'emails/support_agent_invitation.html.twig';

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly TeamRoleService $roles,
        private readonly TenantConnectionProvider $tenant,
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

    /** @return list<array{userId: int, firstname: ?string, lastname: ?string, email: ?string, pending: bool, invitationExpiresAt: ?string}> */
    public function list(): array
    {
        return array_map(fn (User $u) => $this->row($u), $this->roles->members(self::ROLE));
    }

    /**
     * Invite un intervenant `{ email, firstName, lastName, locale? }`.
     *
     * @param array<string, mixed> $body
     * @return array{staff: array<string, mixed>, changed: bool, invitation: ?array<string, mixed>}
     * @throws AboException 422 EMAIL_INVALID | NAME_REQUIRED | NAME_TOO_LONG | LOCALE_INVALID, 409 STAFF_IS_ADMIN | STAFF_IS_CUSTOMER
     */
    public function invite(array $body, User $by): array
    {
        $email = strtolower(trim(is_string($body['email'] ?? null) ? $body['email'] : ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 180) {
            throw new AboException(422, 'EMAIL_INVALID', 'Adresse de courriel attendue.', 'email');
        }
        $names = [];
        foreach (['firstName', 'lastName'] as $field) {
            $names[$field] = trim(strip_tags(is_string($body[$field] ?? null) ? $body[$field] : ''));
            if ($names[$field] === '') {
                throw new AboException(422, 'NAME_REQUIRED', 'Prénom et nom attendus.', $field);
            }
            if (mb_strlen($names[$field]) > 100) {
                throw new AboException(422, 'NAME_TOO_LONG', '100 caractères au plus.', $field);
            }
        }
        $locale = $body['locale'] ?? null;
        if ($locale !== null && !in_array($locale, [EmailLocale::FRENCH, EmailLocale::ENGLISH], true)) {
            throw new AboException(422, 'LOCALE_INVALID', 'Langue attendue : fr ou en.', 'locale');
        }
        $em = $this->emProvider->getEntityManager();
        $user = $em->getRepository(User::class)->findOneByEmailCaseless($email);
        if ($user !== null) {
            if (array_intersect(['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'], $user->getRoles()) !== []) {
                throw new AboException(409, 'STAFF_IS_ADMIN', 'Ce compte administre le site : il a déjà accès à toutes les dates.', 'email');
            }
            if ($this->isCustomer((int) $user->getId())) {
                throw new AboException(409, 'STAFF_IS_CUSTOMER', 'Ce compte est celui d\'un client (abonnement ou commande) : un intervenant a un compte à lui.', 'email');
            }
            if (in_array(self::ROLE, $user->getRoles(), true)) {
                // déjà intervenant : une invitation en attente est renvoyée (l'ancien lien ne vaut plus), sinon rien ne change
                return $this->isPending($user) ? ['staff' => $this->row($user), 'changed' => false, 'invitation' => $this->send($user, $by, is_string($locale) ? $locale : null)]
                    : ['staff' => $this->row($user), 'changed' => false, 'invitation' => null];
            }
            $this->roles->set($user, self::ROLE, true, ['abonnements']);

            // relecture neuve : un intervenant retiré avant d'avoir choisi son mot de passe, invité de nouveau, reçoit un lien neuf
            return ['staff' => $this->row($user), 'changed' => true, 'invitation' => $this->isPending($user) ? $this->send($user, $by, is_string($locale) ? $locale : null) : null];
        }
        $user = (new User())->setEmail($email)->setUsername($email)->setFirstname($names['firstName'])->setLastname($names['lastName'])
            ->setRoles([])->setIsVerified(false)->setSignupChannel(User::SIGNUP_STAFF_INVITE);
        $user->setPassword($this->hasher->hashPassword($user, bin2hex(random_bytes(24)))); // inutilisable : l'intervenant choisit le sien par le lien
        $em->persist($user);
        $em->flush();
        $this->roles->set($user, self::ROLE, true, ['abonnements', 'invite']);

        return ['staff' => $this->row($user), 'changed' => true, 'invitation' => $this->send($user, $by, is_string($locale) ? $locale : null)];
    }

    /**
     * Retire le rôle : l'accès tombe dès la requête suivante (rôles relus en base), ses sessions sont closes ; ses dates et créneaux à venir
     * redeviennent sans intervenant, les dates passées gardent leur trace.
     *
     * @return array{userId: int, unassigned: int}
     * @throws AboException 404 STAFF_NOT_FOUND
     */
    public function revoke(int $userId): array
    {
        $user = $this->emProvider->getEntityManager()->find(User::class, $userId);
        if (!$user instanceof User || !in_array(self::ROLE, $user->getRoles(), true)) {
            throw new AboException(404, 'STAFF_NOT_FOUND', 'Intervenant introuvable.');
        }
        $this->roles->set($user, self::ROLE, false, ['abonnements']);
        $user->setResetToken(null)->setResetTokenExpiresAt(null);
        $this->emProvider->getEntityManager()->flush();
        $conn = $this->conn();
        $conn->executeStatement('DELETE FROM refresh_tokens WHERE username = ?', [(string) $user->getUserIdentifier()]);
        $now = self::utc(new \DateTimeImmutable());
        $unassigned = (int) $conn->executeStatement("UPDATE benefit_occurrence SET staff_user_id = NULL WHERE staff_user_id = ? AND status IN ('available', 'scheduled')", [$userId]);
        $unassigned += (int) $conn->executeStatement('UPDATE benefit_slot SET staff_user_id = NULL WHERE staff_user_id = ? AND starts_at > ?', [$userId, $now]);
        $unassigned += (int) $conn->executeStatement('UPDATE benefit_slot_rule SET staff_user_id = NULL WHERE staff_user_id = ?', [$userId]);
        $unassigned += (int) $conn->executeStatement('UPDATE benefit SET default_staff_user_id = NULL WHERE default_staff_user_id = ?', [$userId]);

        return ['userId' => $userId, 'unassigned' => $unassigned];
    }

    /**
     * L'intervenant d'une date planifiée ou à planifier (`null` : celui du créneau ou de la prestation), sous le verrou de l'abonnement.
     *
     * @return array{occurrenceId: int, staffUserId: ?int, staffName: ?string}
     * @throws AboException 404 OCCURRENCE_NOT_FOUND, 409 OCCURRENCE_NOT_ASSIGNABLE, 422 STAFF_INVALID
     */
    public function assign(Subscription $subscription, int $occurrenceId, mixed $staffUserId, ?string $by): array
    {
        if ($staffUserId !== null && !is_int($staffUserId) && !(is_string($staffUserId) && ctype_digit($staffUserId))) {
            throw new AboException(422, 'STAFF_INVALID', 'Intervenant : un compte qui porte le rôle d\'intervenant, ou null.', 'staffUserId');
        }
        $staff = $staffUserId !== null ? (int) $staffUserId : null;
        $this->assertStaff($staff);
        SubscriptionLock::run($this->conn(), SubscriptionLock::key((string) $this->tenant->getTenantCode(), $subscription->getStripeSubscriptionId(), $subscription->getId()), fn () => $this->conn()->transactional(function (Connection $conn) use ($subscription, $occurrenceId, $staff) {
            $status = $conn->fetchOne('SELECT status FROM benefit_occurrence WHERE id = ? AND subscription_id = ? FOR UPDATE', [$occurrenceId, $subscription->getId()]);
            if ($status === false) {
                throw new AboException(404, 'OCCURRENCE_NOT_FOUND', 'Droit introuvable.', 'occurrenceId');
            }
            if (!in_array($status, [BenefitOccurrence::STATUS_AVAILABLE, BenefitOccurrence::STATUS_SCHEDULED], true)) {
                throw new AboException(409, 'OCCURRENCE_NOT_ASSIGNABLE', 'Seule une date à planifier ou planifiée reçoit un intervenant.');
            }
            $conn->executeStatement('UPDATE benefit_occurrence SET staff_user_id = ? WHERE id = ?', [$staff, $occurrenceId]);
        }));
        $this->audit->record('subscription', (int) $subscription->getId(), 'occurrence_staff', null, ['occurrenceId' => $occurrenceId, 'staffUserId' => $staff, 'by' => $by], ['occurrenceId', 'staffUserId']);

        return ['occurrenceId' => $occurrenceId, 'staffUserId' => $staff, 'staffName' => $staff !== null ? ($this->names([$staff])[$staff] ?? null) : null];
    }

    /** Un intervenant : un compte du site qui porte le rôle (ou null) @throws AboException 422 STAFF_INVALID */
    public function assertStaff(?int $userId, string $field = 'staffUserId'): void
    {
        if ($userId === null) {
            return;
        }
        $user = $this->emProvider->getEntityManager()->find(User::class, $userId);
        if (!$user instanceof User || !in_array(self::ROLE, $user->getRoles(), true)) {
            throw new AboException(422, 'STAFF_INVALID', 'Intervenant : un compte qui porte le rôle d\'intervenant (« Intervenants » du suivi des abonnements).', $field);
        }
    }

    /**
     * Prénom et nom des intervenants, pour l'équipe seulement (jamais pour le modèle ni l'abonné).
     *
     * @param list<int> $ids
     * @return array<int, string>
     */
    public function names(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, fn ($v) => is_int($v) && $v > 0)));
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach ($this->conn()->executeQuery('SELECT id, firstname, lastname FROM "user" WHERE id IN (?)', [$ids], [\Doctrine\DBAL\ArrayParameterType::INTEGER])->fetchAllAssociative() as $r) {
            $name = trim(($r['firstname'] ?? '') . ' ' . ($r['lastname'] ?? ''));
            $out[(int) $r['id']] = $name !== '' ? $name : sprintf('Compte n° %d', (int) $r['id']);
        }

        return $out;
    }

    // ---- interne ----------------------------------------------------------------------------------------------------------------------------

    /**
     * Un client : un compte qui a souscrit, commandé sur la boutique web ou mobile (jamais une vente en caisse, où le compte est le
     * caissier), ou qui appartient à une application de clients (boutique, Living Memory). Relecture neuve : le rôle ROLE_USER_INTERNET ne
     * le dit pas (tout intervenant le porte).
     */
    private function isCustomer(int $userId): bool
    {
        return $this->conn()->fetchOne('SELECT 1 FROM subscription WHERE user_id = ?
            UNION ALL SELECT 1 FROM "order" o JOIN order_source s ON s.id = o.order_source_id WHERE o.user_id_id = ? AND s.name IN (\'Ecommerce\', \'Mobile_app\')
            UNION ALL SELECT 1 FROM user_app_membership WHERE user_id = ? AND app IN (\'boutique\', \'livingmemory\') LIMIT 1', [$userId, $userId, $userId]) !== false;
    }

    private function isPending(User $user): bool
    {
        return !$user->isVerified() && $user->getSignupChannel() === User::SIGNUP_STAFF_INVITE;
    }

    /** @return array{userId: int, firstname: ?string, lastname: ?string, email: ?string, pending: bool, invitationExpiresAt: ?string} */
    private function row(User $user): array
    {
        $pending = $this->isPending($user);

        return ['userId' => (int) $user->getId(), 'firstname' => $user->getFirstname(), 'lastname' => $user->getLastname(), 'email' => $user->getEmail(), 'pending' => $pending,
            'invitationExpiresAt' => $pending ? $user->getResetTokenExpiresAt()?->format(\DATE_ATOM) : null];
    }

    /** Pose un jeton neuf (l'ancien ne vaut plus), envoie le lien et rend l'état de l'invitation, jamais le jeton */
    private function send(User $user, User $by, ?string $locale): array
    {
        $token = bin2hex(random_bytes(32));
        $expires = new \DateTimeImmutable('+' . self::INVITATION_HOURS . ' hours');
        $user->setResetToken($token)->setResetTokenExpiresAt($expires);
        $this->emProvider->getEntityManager()->flush();
        $language = $this->locale->language($locale);
        $link = $this->adminSpace->activation($token); // jeton dans le fragment de l'adresse : jamais journalisé par un serveur
        $code = (string) $this->tenants->getCurrentTenantCode();
        $site = $code !== '' ? $this->hosts->describe($code) : null;
        $t = fn (string $key, array $params = []) => $this->translator->trans('abo_staff_invitation.' . $key, $params, 'emails', $language);
        $inviter = trim((string) $by->getFirstname()) ?: (string) $by->getUserIdentifier();
        $params = ['%site%' => $this->siteName->name(), '%inviter%' => $inviter, '%days%' => intdiv(self::INVITATION_HOURS, 24)];
        $sent = false;
        if ($link !== null) {
            try {
                $sent = $this->mailer->sendTemplatedEmail((string) $user->getEmail(), $t('subject', $params), self::TEMPLATE, [
                    'title' => $t('title', $params), 'greeting' => $t('greeting', ['%name%' => $user->getFirstname()]), 'message' => $t('message', $params),
                    'button' => $t('button'), 'link' => $link, 'validity' => $t('validity', $params), 'ignore' => $t('ignore'), 'siteLine' => $params['%site%'],
                ], $language, (string) ($site['host'] ?? $code));
            } catch (\Throwable $e) {
                $this->logger->error('Abonnements : courriel d\'invitation d\'un intervenant en échec (' . $e::class . ').');
            }
        } else {
            $this->logger->warning('Abonnements : invitation d\'un intervenant sans lien (aucune adresse d\'espace d\'administration connue pour ce site).');
        }

        return ['emailSent' => $sent, 'expiresAt' => $expires->format(\DATE_ATOM), 'validHours' => self::INVITATION_HOURS];
    }

    private function conn(): Connection
    {
        return $this->emProvider->getEntityManager()->getConnection();
    }

    private static function utc(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
